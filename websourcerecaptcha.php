<?php
/**
 * Websource reCAPTCHA — protection anti-spam des formulaires PrestaShop par Google reCAPTCHA.
 *
 * - Google reCAPTCHA v2 « case à cocher », v2 invisible ou v3 (score)
 * - Formulaires : contact, création de compte, connexion, mot de passe oublié, newsletter
 *   (ps_emailsubscription et websourcenewsletter), avis produits (productcomments), compte invité du tunnel
 * - Vérification serveur obligatoire (siteverify) AVANT le traitement du formulaire, sans aucune override
 * - Injection automatique du widget (sélecteurs CSS configurables), compatible formulaires rechargés en AJAX
 * - Exclusions (clients connectés, IP/CIDR, IP de maintenance), consentement cookies optionnel,
 *   fail-safe si Google est injoignable, test des clés, journal des échecs et statistiques
 *
 * Choix technique de l'interception (aucune override) :
 *  1. Hooks de validation natifs quand le cœur ou le module en fournit un, car ils conservent toutes
 *     les données saisies et affichent l'erreur dans le formulaire :
 *       - actionSubmitAccountBefore          (création de compte, et compte invité du tunnel en PS 8+) ;
 *       - actionNewsletterRegistrationBefore (ps_emailsubscription : erreur affichée dans le bloc).
 *  2. Sinon actionFrontControllerInitBefore, déclenché au tout début de FrontController::init(), donc
 *     avant postProcess()/initContent() qui traitent les POST (PS 1.7.8 → 9.x). Si la vérification
 *     échoue, on « neutralise » la soumission en retirant le champ déclencheur (submitMessage,
 *     submitLogin, email…) de $_POST/$_GET : le cœur affiche alors la page normalement, sans rien
 *     traiter, avec notre message dans $controller->errors. Pour les points d'entrée AJAX/JSON
 *     (avis produits, websourcenewsletter) on répond directement un JSON au format attendu par
 *     le JavaScript du module, puis on arrête l'exécution proprement.
 *
 * @author    Websource
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/WsrcIp.php';
require_once __DIR__ . '/classes/WsrcVerifier.php';
require_once __DIR__ . '/classes/WsrcLog.php';

class WebsourceRecaptcha extends Module
{
    /** Valeurs par défaut de la configuration générale. */
    const DEFAULTS = [
        'WSRC_ENABLED' => 1,
        'WSRC_VERSION' => 'v2_checkbox',
        'WSRC_SITE_KEY' => '',
        'WSRC_SECRET_KEY' => '',
        'WSRC_V3_THRESHOLD' => '0.5',
        'WSRC_THEME' => 'light',
        'WSRC_SIZE' => 'normal',
        'WSRC_BADGE' => 'bottomright',
        'WSRC_HIDE_BADGE' => 0,
        'WSRC_CHECK_HOSTNAME' => 0,
        'WSRC_FAIL_OPEN' => 1,
        'WSRC_EXCLUDE_LOGGED' => 0,
        'WSRC_IP_WHITELIST' => '',
        'WSRC_EXCLUDE_MAINT_IP' => 0,
        'WSRC_CONSENT_REQUIRED' => 0,
        'WSRC_CONSENT_CATEGORY' => 'services',
        'WSRC_CONSENT_EVENT' => '',
        'WSRC_LOG_ENABLED' => 1,
    ];

    /**
     * Formulaires protégés : suffixe de configuration, sélecteur CSS par défaut (thème classic ET thème
     * Websource, qui partagent ces gabarits ; websourcenewsletter a sa propre clé), action v3, pages
     * où le widget est injecté (null = toutes), module requis, chargement différé (au premier focus).
     */
    const FORMS = [
        'contact' => [
            'conf' => 'CONTACT',
            'selector' => '.contact-form form',
            'action' => 'contact',
            'pages' => null,
            'module' => 'contactform',
            'lazy' => false,
        ],
        'register' => [
            'conf' => 'REGISTER',
            'selector' => '#customer-form',
            'action' => 'signup',
            'pages' => ['registration', 'authentication'],
            'module' => null,
            'lazy' => false,
        ],
        'login' => [
            'conf' => 'LOGIN',
            'selector' => '#login-form',
            'action' => 'login',
            'pages' => ['authentication', 'order'],
            'module' => null,
            'lazy' => false,
        ],
        'password' => [
            'conf' => 'PASSWORD',
            'selector' => 'form.forgotten-password',
            'action' => 'password_reset',
            'pages' => ['password'],
            'module' => null,
            'lazy' => false,
        ],
        'newsletter' => [
            'conf' => 'NEWSLETTER',
            'selector' => '.block_newsletter form',
            'action' => 'newsletter',
            'pages' => null,
            'module' => 'ps_emailsubscription',
            'lazy' => true,
        ],
        'wsnewsletter' => [
            'conf' => 'WSNEWSLETTER',
            'selector' => 'form[data-wsnl-form]',
            'action' => 'newsletter',
            'pages' => null,
            'module' => 'websourcenewsletter',
            'lazy' => true,
        ],
        'review' => [
            'conf' => 'REVIEW',
            'selector' => '#post-product-comment-form',
            'action' => 'product_review',
            'pages' => ['product'],
            'module' => 'productcomments',
            'lazy' => true,
        ],
        'guest' => [
            'conf' => 'GUEST',
            'selector' => '#checkout-guest-form #customer-form',
            'action' => 'guest_checkout',
            'pages' => ['order'],
            'module' => null,
            'lazy' => false,
        ],
    ];

    /** @var array résultat des vérifications de la requête courante (un jeton ne se vérifie qu'une fois) */
    private static $verdicts = [];

    /** @var array valeurs POST à remettre en place après le traitement (pour réafficher la saisie) */
    private static $restorePost = [];

    public function __construct()
    {
        $this->name = 'websourcerecaptcha';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'Websource';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.8.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('Websource reCAPTCHA');
        $this->description = $this->l('Protège vos formulaires (contact, compte, connexion, newsletter, avis…) contre le spam avec Google reCAPTCHA v2 ou v3, avec vérification côté serveur.');
        $this->confirmUninstall = $this->l('Supprimer la configuration, le journal et les statistiques reCAPTCHA ?');
    }

    // ------------------------------------------------------------------
    // Installation
    // ------------------------------------------------------------------

    public function install()
    {
        if (!parent::install() || !WsrcLog::installSql()) {
            return false;
        }
        foreach (self::DEFAULTS as $key => $value) {
            Configuration::updateValue($key, $value);
        }
        foreach (self::FORMS as $def) {
            Configuration::updateValue('WSRC_FORM_' . $def['conf'], 1);
            Configuration::updateValue('WSRC_SEL_' . $def['conf'], $def['selector']);
            Configuration::updateValue('WSRC_ACTION_' . $def['conf'], $def['action']);
        }

        return $this->registerHook('actionFrontControllerInitBefore')
            && $this->registerHook('actionSubmitAccountBefore')
            && $this->registerHook('actionNewsletterRegistrationBefore')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('displayHeader');
    }

    public function uninstall()
    {
        foreach ($this->configKeys() as $key) {
            Configuration::deleteByName($key);
        }
        Configuration::deleteByName('WSRC_LAST_PURGE');

        return WsrcLog::uninstallSql() && parent::uninstall();
    }

    /**
     * Toutes les clés de configuration du module (hors WSRC_LAST_PURGE, interne).
     *
     * @return string[]
     */
    private function configKeys()
    {
        $keys = array_keys(self::DEFAULTS);
        foreach (self::FORMS as $def) {
            $keys[] = 'WSRC_FORM_' . $def['conf'];
            $keys[] = 'WSRC_SEL_' . $def['conf'];
            $keys[] = 'WSRC_ACTION_' . $def['conf'];
        }

        return $keys;
    }

    // ------------------------------------------------------------------
    // État et exclusions
    // ------------------------------------------------------------------

    /**
     * Module actif et clés renseignées ? Sans clés, aucune protection (le site reste utilisable).
     *
     * @return bool
     */
    private function isActive()
    {
        return (bool) Configuration::get('WSRC_ENABLED')
            && trim((string) Configuration::get('WSRC_SITE_KEY')) !== ''
            && trim((string) Configuration::get('WSRC_SECRET_KEY')) !== '';
    }

    /**
     * @param string $form
     *
     * @return bool
     */
    private function isFormEnabled($form)
    {
        if (!array_key_exists($form, self::FORMS) || !Configuration::get('WSRC_FORM_' . self::FORMS[$form]['conf'])) {
            return false;
        }
        $module = self::FORMS[$form]['module'];

        return $module === null || Module::isEnabled($module);
    }

    /**
     * Visiteur exempté (client connecté si l'option est active, IP autorisée, IP de maintenance) ?
     *
     * @return bool
     */
    private function isVisitorExempt()
    {
        if (Configuration::get('WSRC_EXCLUDE_LOGGED')
            && isset($this->context->customer)
            && $this->context->customer->isLogged()
        ) {
            return true;
        }
        $ip = (string) Tools::getRemoteAddr();
        $rules = WsrcIp::parseList((string) Configuration::get('WSRC_IP_WHITELIST'));
        if (Configuration::get('WSRC_EXCLUDE_MAINT_IP')) {
            $rules = array_merge($rules, WsrcIp::parseList((string) Configuration::get('PS_MAINTENANCE_IP')));
        }

        return $rules && WsrcIp::inList($ip, $rules);
    }

    /**
     * Le cœur appelle-t-il actionSubmitAccountBefore pour ce contrôleur ?
     * Création de compte : toujours (1.7.8+). Tunnel de commande (compte invité) : à partir de PS 8.0.
     *
     * @param string $phpSelf
     *
     * @return bool
     */
    private function coreCallsSubmitAccountHook($phpSelf)
    {
        if ($phpSelf === 'order') {
            return version_compare(_PS_VERSION_, '8.0.0', '>=');
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Interception côté serveur
    // ------------------------------------------------------------------

    /**
     * Point d'interception principal : début de FrontController::init(), avant tout traitement du POST.
     */
    public function hookActionFrontControllerInitBefore($params)
    {
        $controller = isset($params['controller']) && is_object($params['controller'])
            ? $params['controller']
            : $this->context->controller;
        if (!is_object($controller) || !$this->isActive()) {
            return;
        }
        $form = $this->detectInitForm($controller);
        if ($form === null || !$this->isFormEnabled($form) || $this->isVisitorExempt()) {
            return;
        }
        if ($this->checkSubmission($form)) {
            return;
        }
        $this->rejectAtInit($form, $controller);
    }

    /**
     * Identifie un formulaire soumis traité par ce hook (contrôleur + champ déclencheur).
     *
     * @param object $controller
     *
     * @return string|null
     */
    private function detectInitForm($controller)
    {
        $phpSelf = isset($controller->php_self) ? (string) $controller->php_self : '';
        $class = strtolower(get_class($controller));
        $moduleName = ($controller instanceof ModuleFrontController && isset($controller->module) && is_object($controller->module))
            ? (string) $controller->module->name
            : '';
        $moduleController = strtolower((string) Tools::getValue('controller'));

        if ($phpSelf === 'contact' && Tools::isSubmit('submitMessage')) {
            return 'contact';
        }
        if (($phpSelf === 'authentication' || $phpSelf === 'order') && Tools::isSubmit('submitLogin')) {
            return 'login';
        }
        if ($phpSelf === 'password' && Tools::isSubmit('email')) {
            return 'password';
        }
        // PS 1.7.8 : le tunnel n'appelle pas actionSubmitAccountBefore, on intercepte ici.
        if ($phpSelf === 'order' && Tools::isSubmit('submitCreate') && !$this->coreCallsSubmitAccountHook('order')) {
            return 'guest';
        }
        if ($class === 'productcommentspostcommentmodulefrontcontroller'
            || ($moduleName === 'productcomments' && $moduleController === 'postcomment')
        ) {
            return 'review';
        }
        if (($class === 'websourcenewslettersubscribemodulefrontcontroller'
            || ($moduleName === 'websourcenewsletter' && $moduleController === 'subscribe'))
            && Tools::isSubmit('wsnl_submit')
        ) {
            return 'wsnewsletter';
        }

        return null;
    }

    /**
     * Refus d'une soumission interceptée dans actionFrontControllerInitBefore.
     *
     * @param string $form
     * @param object $controller
     */
    private function rejectAtInit($form, $controller)
    {
        $message = $this->failureMessage($form);

        if ($form === 'review') {
            // Format attendu par productcomments/views/js/post-comment.js (fenêtre d'erreur du module).
            $this->sendJson(['success' => false, 'errors' => [$message]]);
        }

        if ($form === 'wsnewsletter') {
            if ($this->isAjaxRequest()) {
                // Format attendu par websourcenewsletter/views/js/front.js (message sous le champ).
                $this->sendJson(['success' => false, 'message' => $message, 'field' => '']);
            }
            // Envoi classique (sans JavaScript) : retour à la page d'origine avec le message.
            $controller->errors[] = $message;
            $back = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
            if ($back === '' || !Tools::urlBelongsToShop($back)) {
                $back = $this->context->link->getPageLink('index');
            }
            $controller->redirectWithNotifications($back);

            return;
        }

        // Neutralisation : sans champ déclencheur, le contrôleur n'exécute pas le traitement.
        $triggers = [
            'contact' => ['submitMessage'],
            'login' => ['submitLogin'],
            'password' => ['email'],
            'guest' => ['submitCreate'],
        ];
        foreach (isset($triggers[$form]) ? $triggers[$form] : [] as $field) {
            if ($form === 'password' && isset($_POST[$field])) {
                // Remis en place dans displayHeader (après postProcess) pour réafficher l'adresse saisie.
                self::$restorePost[$field] = $_POST[$field];
            }
            unset($_POST[$field], $_GET[$field], $_REQUEST[$field]);
        }
        if ($form === 'login' && isset($controller->php_self) && $controller->php_self === 'order') {
            // Tunnel : rester sur l'onglet « Se connecter ».
            $_GET['login'] = '1';
        }

        $controller->errors[] = $message;
        $this->flagFailureForJs($form, $message);
    }

    /**
     * Création de compte (toutes versions) et compte invité du tunnel (PS 8+).
     * Retourner false bloque l'enregistrement ; le formulaire est réaffiché avec la saisie.
     *
     * @return bool
     */
    public function hookActionSubmitAccountBefore($params)
    {
        if (!$this->isActive()) {
            return true;
        }
        $controller = $this->context->controller;
        $phpSelf = is_object($controller) && isset($controller->php_self) ? (string) $controller->php_self : '';
        $form = $phpSelf === 'order' ? 'guest' : 'register';
        if (!$this->isFormEnabled($form) || $this->isVisitorExempt()) {
            return true;
        }
        if ($this->checkSubmission($form)) {
            return true;
        }
        $message = $this->failureMessage($form);
        if (is_object($controller)) {
            $controller->errors[] = $message;
        }
        $this->flagFailureForJs($form, $message);

        return false;
    }

    /**
     * ps_emailsubscription : le module lit $params['hookError'] (passé par référence) et l'affiche
     * dans le bloc newsletter, en envoi classique comme en AJAX.
     */
    public function hookActionNewsletterRegistrationBefore($params)
    {
        if (isset($params['hookError']) && $params['hookError'] !== null) {
            return;
        }
        if (isset($params['module']) && $params['module'] !== '' && $params['module'] !== 'ps_emailsubscription') {
            return;
        }
        if (!$this->isActive() || !$this->isFormEnabled('newsletter') || $this->isVisitorExempt()) {
            return;
        }
        if ($this->checkSubmission('newsletter')) {
            return;
        }
        $params['hookError'] = $this->failureMessage('newsletter');
        $this->flagFailureForJs('newsletter', $params['hookError']);
    }

    /**
     * Vérifie le jeton de la requête pour un formulaire (résultat mis en cache pour la requête).
     *
     * @param string $form
     *
     * @return bool true = la soumission peut être traitée
     */
    private function checkSubmission($form)
    {
        if (isset(self::$verdicts[$form])) {
            return self::$verdicts[$form]['allowed'];
        }

        $token = trim((string) Tools::getValue('wsrc_token', ''));
        if ($token === '') {
            $token = trim((string) Tools::getValue('g-recaptcha-response', ''));
        }
        $ip = (string) Tools::getRemoteAddr();
        $version = (string) Configuration::get('WSRC_VERSION');

        $result = WsrcVerifier::verify(
            (string) Configuration::get('WSRC_SECRET_KEY'),
            $token,
            $ip,
            [
                'version' => $version,
                'action' => $this->formAction($form),
                'threshold' => $this->threshold(),
                'hostnames' => Configuration::get('WSRC_CHECK_HOSTNAME') ? $this->shopHostnames() : [],
            ]
        );

        // Fail-safe : Google injoignable → laisser passer (défaut) ou bloquer, selon la configuration.
        $allowed = $result['ok'] || ($result['unreachable'] && Configuration::get('WSRC_FAIL_OPEN'));

        self::$verdicts[$form] = ['allowed' => $allowed, 'codes' => $result['codes'], 'unreachable' => $result['unreachable']];

        // Le journal détaillé ne garde que les échecs (y compris « laissé passer car Google injoignable »).
        $detail = (bool) Configuration::get('WSRC_LOG_ENABLED') && (!$allowed || $result['unreachable']);
        try {
            WsrcLog::record(
                (int) $this->context->shop->id,
                $form,
                !$allowed,
                $detail,
                $ip,
                $result['score'],
                $result['codes']
            );
        } catch (Exception $e) {
            // Le journal ne doit jamais empêcher le traitement du formulaire.
        }

        return $allowed;
    }

    /**
     * Message d'erreur adapté à la cause de l'échec.
     *
     * @param string $form
     *
     * @return string
     */
    private function failureMessage($form)
    {
        $verdict = isset(self::$verdicts[$form]) ? self::$verdicts[$form] : ['codes' => [], 'unreachable' => false];
        if ($verdict['unreachable']) {
            return $this->l('Le service de vérification anti-robot est momentanément indisponible. Merci de réessayer dans quelques instants.');
        }
        if (in_array('missing-input-response', $verdict['codes'], true)) {
            return Configuration::get('WSRC_VERSION') === 'v2_checkbox'
                ? $this->l('Merci de cocher la case « Je ne suis pas un robot » avant d\'envoyer le formulaire.')
                : $this->l('La vérification anti-robot n\'a pas pu être effectuée. Vérifiez que JavaScript est activé puis réessayez.');
        }
        if (in_array('timeout-or-duplicate', $verdict['codes'], true)) {
            return $this->l('La vérification anti-robot a expiré. Merci de valider à nouveau puis de renvoyer le formulaire.');
        }

        return $this->l('La vérification anti-robot (reCAPTCHA) a échoué. Merci de réessayer.');
    }

    /**
     * Transmet l'échec au JavaScript pour afficher le message dans le formulaire concerné
     * (si le thème ne l'affiche pas déjà).
     *
     * @param string $form
     * @param string $message
     */
    private function flagFailureForJs($form, $message)
    {
        Media::addJsDef(['wsrcFailure' => ['form' => $form, 'message' => $message]]);
    }

    /**
     * Réponse JSON puis arrêt propre (points d'entrée AJAX des modules).
     *
     * @param array $data
     */
    private function sendJson(array $data)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($data);
        exit;
    }

    /**
     * @return bool
     */
    private function isAjaxRequest()
    {
        $xhr = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) : '';

        return $xhr === 'xmlhttprequest' || (string) Tools::getValue('ajax') === '1';
    }

    /**
     * @param string $form
     *
     * @return string
     */
    private function formAction($form)
    {
        $conf = array_key_exists($form, self::FORMS) ? self::FORMS[$form]['conf'] : '';
        $action = (string) Configuration::get('WSRC_ACTION_' . $conf);

        return $action !== '' ? $action : (array_key_exists($form, self::FORMS) ? self::FORMS[$form]['action'] : $form);
    }

    /**
     * @return float
     */
    private function threshold()
    {
        $value = (float) str_replace(',', '.', (string) Configuration::get('WSRC_V3_THRESHOLD'));

        return max(0.0, min(1.0, $value));
    }

    /**
     * Noms d'hôte acceptés pour le contrôle « hostname » : domaines des boutiques actives.
     *
     * @return string[]
     */
    private function shopHostnames()
    {
        $hosts = [];
        $rows = Db::getInstance()->executeS('SELECT `domain`, `domain_ssl` FROM `' . _DB_PREFIX_ . 'shop_url` WHERE `active` = 1');
        foreach ($rows ?: [] as $row) {
            foreach (['domain', 'domain_ssl'] as $col) {
                $host = strtolower(preg_replace('/:\d+$/', '', trim((string) $row[$col])));
                if ($host !== '') {
                    $hosts[] = $host;
                }
            }
        }

        return array_values(array_unique($hosts));
    }

    // ------------------------------------------------------------------
    // Front : chargement des ressources
    // ------------------------------------------------------------------

    /**
     * Remet en place les valeurs POST retirées lors d'une neutralisation (displayHeader s'exécute
     * après postProcess) : le gabarit « mot de passe oublié » réaffiche ainsi l'adresse saisie.
     */
    public function hookDisplayHeader($params)
    {
        foreach (self::$restorePost as $field => $value) {
            $_POST[$field] = $value;
        }
        self::$restorePost = [];

        return '';
    }

    /**
     * Script et feuille de style du module, uniquement sur les pages qui peuvent contenir un formulaire
     * protégé. Le script Google lui-même n'est chargé que si un formulaire ciblé est réellement présent.
     */
    public function hookActionFrontControllerSetMedia($params)
    {
        $controller = $this->context->controller;
        if (!is_object($controller) || !$this->isActive() || $this->isVisitorExempt()) {
            return;
        }
        $phpSelf = isset($controller->php_self) ? (string) $controller->php_self : '';
        $forms = $this->frontForms($phpSelf);
        if (!$forms) {
            return;
        }

        $controller->registerStylesheet(
            'modules-websourcerecaptcha',
            'modules/' . $this->name . '/views/css/front.css',
            ['media' => 'all', 'priority' => 150]
        );
        $controller->registerJavascript(
            'modules-websourcerecaptcha',
            'modules/' . $this->name . '/views/js/front.js',
            ['position' => 'bottom', 'priority' => 150]
        );

        $iso = isset($this->context->language->iso_code) ? (string) $this->context->language->iso_code : 'fr';
        $version = (string) Configuration::get('WSRC_VERSION');

        Media::addJsDef([
            'wsrcConfig' => [
                'version' => in_array($version, ['v2_checkbox', 'v2_invisible', 'v3'], true) ? $version : 'v2_checkbox',
                'siteKey' => (string) Configuration::get('WSRC_SITE_KEY'),
                'lang' => $iso,
                'theme' => Configuration::get('WSRC_THEME') === 'dark' ? 'dark' : 'light',
                'size' => Configuration::get('WSRC_SIZE') === 'compact' ? 'compact' : 'normal',
                'badge' => in_array(Configuration::get('WSRC_BADGE'), ['bottomright', 'bottomleft', 'inline'], true)
                    ? (string) Configuration::get('WSRC_BADGE')
                    : 'bottomright',
                'hideBadge' => (bool) Configuration::get('WSRC_HIDE_BADGE'),
                'legal' => $this->legalHtml(),
                'consent' => [
                    'required' => (bool) Configuration::get('WSRC_CONSENT_REQUIRED'),
                    'category' => (string) Configuration::get('WSRC_CONSENT_CATEGORY'),
                    'event' => (string) Configuration::get('WSRC_CONSENT_EVENT'),
                ],
                'forms' => $forms,
                'msg' => [
                    'required' => $this->l('Merci de cocher la case « Je ne suis pas un robot » avant d\'envoyer le formulaire.'),
                    'unavailable' => $this->l('La vérification anti-robot n\'a pas pu être chargée. Vérifiez votre connexion ou votre bloqueur de contenu, puis réessayez.'),
                    'consent' => $this->l('Ce formulaire est protégé contre le spam par Google reCAPTCHA. Pour l\'envoyer, autorisez le chargement de ce service.'),
                    'consentButton' => $this->l('Autoriser reCAPTCHA'),
                ],
            ],
        ]);
    }

    /**
     * Formulaires à équiper sur la page courante.
     *
     * @param string $phpSelf
     *
     * @return array
     */
    private function frontForms($phpSelf)
    {
        $out = [];
        foreach (self::FORMS as $key => $def) {
            if (!$this->isFormEnabled($key)) {
                continue;
            }
            if ($def['pages'] !== null && !in_array($phpSelf, $def['pages'], true)) {
                continue;
            }
            $selector = trim((string) Configuration::get('WSRC_SEL_' . $def['conf']));
            $out[] = [
                'key' => $key,
                'selector' => $selector !== '' ? $selector : $def['selector'],
                'action' => $this->formAction($key),
                'lazy' => (bool) $def['lazy'],
            ];
        }

        return $out;
    }

    /**
     * Mention légale exigée par Google lorsque le badge est masqué.
     *
     * @return string
     */
    private function legalHtml()
    {
        if (!Configuration::get('WSRC_HIDE_BADGE') || Configuration::get('WSRC_VERSION') === 'v2_checkbox') {
            return '';
        }
        // Balises insérées après échappement de la traduction (paramètre « tags » de {l}).
        $this->context->smarty->assign([
            'wsrc_legal_tags' => [
                '<a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer">',
                '<a href="https://policies.google.com/terms" target="_blank" rel="noopener noreferrer">',
            ],
        ]);

        return trim($this->display(__FILE__, 'views/templates/hook/legal.tpl'));
    }

    // ------------------------------------------------------------------
    // Back-office
    // ------------------------------------------------------------------

    public function getContent()
    {
        if ((string) Tools::getValue('wsrcAjax') === 'testToken') {
            $this->ajaxTestToken();
        }

        $output = '';

        if (Tools::isSubmit('submitWsrcConfig')) {
            $output .= $this->processConfiguration();
        } elseif (Tools::isSubmit('submitWsrcTest')) {
            $output .= $this->processKeyTest();
        } elseif (Tools::isSubmit('submitWsrcClearLog')) {
            WsrcLog::clear((int) $this->context->shop->id);
            $output .= $this->displayConfirmation($this->l('Le journal a été vidé.'));
        }

        $output .= $this->renderWarnings();

        return $output . $this->renderForm() . $this->renderDashboard();
    }

    /**
     * URL de la page de configuration (PS 1.7.8 : contrôleur legacy ; PS 8/9 : route Symfony avec _token).
     *
     * @param array $extra paramètres supplémentaires
     *
     * @return string
     */
    private function configUrl(array $extra = [])
    {
        return $this->context->link->getAdminLink('AdminModules', true, [], array_merge(['configure' => $this->name], $extra));
    }

    /**
     * Avertissements de configuration (clés manquantes, HTTP sortant indisponible).
     *
     * @return string
     */
    private function renderWarnings()
    {
        $out = '';
        if (trim((string) Configuration::get('WSRC_SITE_KEY')) === '' || trim((string) Configuration::get('WSRC_SECRET_KEY')) === '') {
            $out .= $this->displayWarning($this->l('Renseignez la clé du site et la clé secrète (console Google reCAPTCHA) : tant qu\'elles sont absentes, aucun formulaire n\'est protégé.'));
        }
        if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) {
            $out .= $this->displayWarning($this->l('Ni cURL ni allow_url_fopen ne sont disponibles : le serveur ne peut pas joindre Google pour vérifier les jetons.'));
        }

        return $out;
    }

    /**
     * Enregistrement du formulaire de configuration.
     *
     * @return string
     */
    private function processConfiguration()
    {
        $errors = [];
        $warnings = [];

        foreach (['WSRC_ENABLED', 'WSRC_HIDE_BADGE', 'WSRC_CHECK_HOSTNAME', 'WSRC_FAIL_OPEN', 'WSRC_EXCLUDE_LOGGED',
            'WSRC_EXCLUDE_MAINT_IP', 'WSRC_CONSENT_REQUIRED', 'WSRC_LOG_ENABLED'] as $key) {
            Configuration::updateValue($key, Tools::getValue($key) ? 1 : 0);
        }

        $version = (string) Tools::getValue('WSRC_VERSION');
        Configuration::updateValue('WSRC_VERSION', in_array($version, ['v2_checkbox', 'v2_invisible', 'v3'], true) ? $version : 'v2_checkbox');
        Configuration::updateValue('WSRC_THEME', Tools::getValue('WSRC_THEME') === 'dark' ? 'dark' : 'light');
        Configuration::updateValue('WSRC_SIZE', Tools::getValue('WSRC_SIZE') === 'compact' ? 'compact' : 'normal');
        $badge = (string) Tools::getValue('WSRC_BADGE');
        Configuration::updateValue('WSRC_BADGE', in_array($badge, ['bottomright', 'bottomleft', 'inline'], true) ? $badge : 'bottomright');

        // Clés : la clé du site est publique ; la clé secrète n'est jamais réaffichée (champ vide = inchangée).
        $siteKey = trim((string) Tools::getValue('WSRC_SITE_KEY'));
        if ($siteKey !== '' && !WsrcVerifier::looksLikeKey($siteKey)) {
            $warnings[] = $this->l('La clé du site ne ressemble pas à une clé reCAPTCHA (40 caractères attendus). Vérifiez-la.');
        }
        Configuration::updateValue('WSRC_SITE_KEY', preg_replace('/[^A-Za-z0-9_\-]/', '', $siteKey));
        $secret = trim((string) Tools::getValue('WSRC_SECRET_KEY'));
        if (Tools::getValue('WSRC_SECRET_CLEAR')) {
            Configuration::updateValue('WSRC_SECRET_KEY', '');
        } elseif ($secret !== '') {
            if (!WsrcVerifier::looksLikeKey($secret)) {
                $warnings[] = $this->l('La clé secrète ne ressemble pas à une clé reCAPTCHA (40 caractères attendus). Utilisez « Tester les clés ».');
            }
            Configuration::updateValue('WSRC_SECRET_KEY', preg_replace('/[^A-Za-z0-9_\-]/', '', $secret));
        }

        $threshold = str_replace(',', '.', trim((string) Tools::getValue('WSRC_V3_THRESHOLD')));
        if (!is_numeric($threshold) || (float) $threshold < 0 || (float) $threshold > 1) {
            $errors[] = $this->l('Le seuil v3 doit être un nombre compris entre 0.0 et 1.0.');
        } else {
            Configuration::updateValue('WSRC_V3_THRESHOLD', (string) round((float) $threshold, 2));
        }

        // Exclusions IP : on ne garde que les règles valides, les autres sont signalées.
        $valid = [];
        $invalid = [];
        foreach (WsrcIp::parseList((string) Tools::getValue('WSRC_IP_WHITELIST')) as $rule) {
            if (WsrcIp::isValidRule($rule)) {
                $valid[] = $rule;
            } else {
                $invalid[] = $rule;
            }
        }
        Configuration::updateValue('WSRC_IP_WHITELIST', implode("\n", $valid));
        if ($invalid) {
            $warnings[] = sprintf($this->l('Adresses IP ignorées car invalides : %s'), implode(', ', array_map('strip_tags', $invalid)));
        }

        $category = (string) Tools::getValue('WSRC_CONSENT_CATEGORY');
        Configuration::updateValue('WSRC_CONSENT_CATEGORY', in_array($category, ['services', 'preferences', 'analytics', 'marketing'], true) ? $category : 'services');
        Configuration::updateValue('WSRC_CONSENT_EVENT', substr(preg_replace('/[^A-Za-z0-9_:.\-]/', '', (string) Tools::getValue('WSRC_CONSENT_EVENT')), 0, 64));

        foreach (self::FORMS as $def) {
            $conf = $def['conf'];
            Configuration::updateValue('WSRC_FORM_' . $conf, Tools::getValue('WSRC_FORM_' . $conf) ? 1 : 0);

            // Sélecteur CSS : texte simple, sans balise (« > » reste permis : combinateur enfant) ; vide = valeur par défaut.
            $selector = trim(str_replace(['<', '{', '}', "\r", "\n"], ' ', (string) Tools::getValue('WSRC_SEL_' . $conf)));
            Configuration::updateValue('WSRC_SEL_' . $conf, $selector !== '' ? substr($selector, 0, 500) : $def['selector']);

            // Action v3 : Google n'accepte que lettres, chiffres, « _ » et « / ».
            $action = preg_replace('/[^A-Za-z0-9_\/]/', '', (string) Tools::getValue('WSRC_ACTION_' . $conf));
            Configuration::updateValue('WSRC_ACTION_' . $conf, $action !== '' ? substr($action, 0, 100) : $def['action']);
        }

        $out = '';
        foreach ($errors as $error) {
            $out .= $this->displayError($error);
        }
        foreach ($warnings as $warning) {
            $out .= $this->displayWarning($warning);
        }
        if (!$errors) {
            $out .= $this->displayConfirmation($this->l('Configuration enregistrée.'));
        }

        return $out;
    }

    /**
     * Test rapide côté serveur (sans JavaScript) : appel siteverify avec un jeton vide.
     *
     * Constat (octobre 2026) : Google répond « invalid-input-response » pour un jeton vide ou factice,
     * que la clé secrète soit valide ou non ; « invalid-input-secret » n'est plus renvoyé dans ce cas.
     * Ce test vérifie donc la connexion sortante vers Google et détecte les clés de test publiques ;
     * la validité réelle du couple de clés est contrôlée par le test complet (jeton réel, voir ajaxTestToken()).
     *
     * @return string
     */
    private function processKeyTest()
    {
        $secret = trim((string) Tools::getValue('WSRC_SECRET_KEY'));
        if ($secret === '') {
            $secret = (string) Configuration::get('WSRC_SECRET_KEY');
        }
        if ($secret === '') {
            return $this->displayError($this->l('Clé secrète : non renseignée.'));
        }

        $test = WsrcVerifier::testSecret($secret);
        switch ($test['status']) {
            case 'invalid':
                return $this->displayError($this->l('Clé secrète : refusée par Google (« invalid-input-secret »). Vérifiez que vous avez copié la clé secrète et non la clé du site.'));
            case 'testkey':
                return $this->displayWarning($this->l('Clé secrète de test Google détectée : tous les jetons sont acceptés. Ne l\'utilisez pas en production.'));
            case 'reachable':
                return $this->displayConfirmation($this->l('Google est joignable depuis le serveur. La clé secrète ne peut être validée qu\'avec un jeton réel : utilisez « Lancer le test complet ».'));
            case 'unreachable':
                return $this->displayError($this->l('Google est injoignable depuis le serveur (délai de 5 secondes dépassé ou connexion sortante bloquée).'));
            default:
                return $this->displayWarning(sprintf(
                    $this->l('Réponse inattendue de Google : %s'),
                    $test['codes'] ? implode(', ', $test['codes']) : '-'
                ));
        }
    }

    /**
     * Test complet (AJAX depuis la page de configuration) : le navigateur obtient un vrai jeton avec la
     * clé du site saisie, le serveur le vérifie avec la clé secrète (saisie ou enregistrée).
     * Valide à la fois le couple de clés, le type de clé et le domaine autorisé.
     */
    private function ajaxTestToken()
    {
        $secret = trim((string) Tools::getValue('wsrc_secret'));
        if ($secret === '') {
            $secret = (string) Configuration::get('WSRC_SECRET_KEY');
        }
        $version = (string) Tools::getValue('wsrc_version');
        $token = (string) Tools::getValue('wsrc_test_token');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['level' => 'danger', 'message' => $this->l('Requête invalide.')]);
        }
        if ($secret === '') {
            $this->sendJson(['level' => 'danger', 'message' => $this->l('Clé secrète : non renseignée.')]);
        }

        // Pas de contrôle d'action ni de seuil : on veut seulement savoir si Google accepte le jeton.
        $result = WsrcVerifier::verify($secret, $token, (string) Tools::getRemoteAddr(), ['version' => 'v2_checkbox']);
        if ($result['unreachable']) {
            $this->sendJson(['level' => 'danger', 'message' => $this->l('Google est injoignable depuis le serveur (délai de 5 secondes dépassé ou connexion sortante bloquée).')]);
        }
        if (!$result['ok']) {
            if (in_array('invalid-input-secret', $result['codes'], true)) {
                $message = $this->l('Clé secrète refusée par Google (« invalid-input-secret »). Vérifiez que vous avez copié la clé secrète et non la clé du site.');
            } elseif (in_array('invalid-input-response', $result['codes'], true)) {
                $message = $this->l('Jeton refusé (« invalid-input-response ») : la clé secrète ne correspond pas à la clé du site.');
            } else {
                $message = sprintf($this->l('Vérification refusée par Google : %s'), implode(', ', $result['codes']));
            }
            $this->sendJson(['level' => 'danger', 'message' => $message]);
        }

        $isV3Key = $result['score'] !== null;
        if ($version === 'v3' && !$isV3Key) {
            $this->sendJson(['level' => 'warning', 'message' => $this->l('Les clés fonctionnent mais ce sont des clés v2 alors que la version v3 est sélectionnée.')]);
        }
        if ($version !== 'v3' && $isV3Key) {
            $this->sendJson(['level' => 'warning', 'message' => $this->l('Les clés fonctionnent mais ce sont des clés v3 alors qu\'une version v2 est sélectionnée.')]);
        }
        $message = $this->l('Clés valides : le jeton a été vérifié par Google.');
        if ($isV3Key) {
            $message .= ' ' . sprintf($this->l('Score obtenu : %s.'), number_format((float) $result['score'], 1));
        }
        $this->sendJson(['level' => 'success', 'message' => $message]);
    }

    /**
     * Formulaire de configuration (HelperForm, compatible PS 1.7.8 → 9).
     *
     * @return string
     */
    private function renderForm()
    {
        $switch = function ($name, $label, $desc = '') {
            return [
                'type' => 'switch',
                'name' => $name,
                'label' => $label,
                'desc' => $desc,
                'is_bool' => true,
                'values' => [
                    ['id' => $name . '_on', 'value' => 1, 'label' => $this->l('Oui')],
                    ['id' => $name . '_off', 'value' => 0, 'label' => $this->l('Non')],
                ],
            ];
        };
        $select = function ($name, $label, array $options, $desc = '') {
            $query = [];
            foreach ($options as $id => $text) {
                $query[] = ['id' => $id, 'name' => $text];
            }

            return [
                'type' => 'select',
                'name' => $name,
                'label' => $label,
                'desc' => $desc,
                'options' => ['query' => $query, 'id' => 'id', 'name' => 'name'],
            ];
        };
        $submit = ['title' => $this->l('Enregistrer'), 'name' => 'submitWsrcConfig'];

        $secretSet = trim((string) Configuration::get('WSRC_SECRET_KEY')) !== '';

        $general = [
            'form' => [
                'legend' => ['title' => $this->l('Clés et version de reCAPTCHA'), 'icon' => 'icon-key'],
                'description' => $this->l('Créez vos clés sur https://www.google.com/recaptcha/admin en choisissant le même type que la version sélectionnée ci-dessous, et ajoutez le domaine de la boutique.'),
                'input' => [
                    $switch('WSRC_ENABLED', $this->l('Protection active')),
                    $select('WSRC_VERSION', $this->l('Version'), [
                        'v2_checkbox' => $this->l('reCAPTCHA v2 — case « Je ne suis pas un robot »'),
                        'v2_invisible' => $this->l('reCAPTCHA v2 — invisible'),
                        'v3' => $this->l('reCAPTCHA v3 — score (sans interaction)'),
                    ], $this->l('Les clés Google sont propres à une version : changer de version impose de nouvelles clés.')),
                    [
                        'type' => 'text',
                        'name' => 'WSRC_SITE_KEY',
                        'label' => $this->l('Clé du site'),
                        'desc' => $this->l('Clé publique, utilisée dans le navigateur.'),
                        'autocomplete' => false,
                    ],
                    [
                        'type' => 'password',
                        'name' => 'WSRC_SECRET_KEY',
                        'label' => $this->l('Clé secrète'),
                        'desc' => ($secretSet
                            ? $this->l('Une clé secrète est enregistrée. Laissez vide pour la conserver.')
                            : $this->l('Aucune clé secrète enregistrée.'))
                            . ' ' . $this->l('Elle n\'est jamais réaffichée ni transmise au navigateur.'),
                        'autocomplete' => false,
                    ],
                    $switch('WSRC_SECRET_CLEAR', $this->l('Effacer la clé secrète enregistrée')),
                    [
                        'type' => 'text',
                        'name' => 'WSRC_V3_THRESHOLD',
                        'label' => $this->l('Seuil v3'),
                        'desc' => $this->l('Score minimal accepté, de 0.0 (tout accepter) à 1.0 (très strict). Recommandé : 0.5.'),
                        'class' => 'fixed-width-sm',
                    ],
                    $select('WSRC_THEME', $this->l('Thème (v2)'), [
                        'light' => $this->l('Clair'),
                        'dark' => $this->l('Sombre'),
                    ]),
                    $select('WSRC_SIZE', $this->l('Taille (v2 case à cocher)'), [
                        'normal' => $this->l('Normale'),
                        'compact' => $this->l('Compacte'),
                    ]),
                    $select('WSRC_BADGE', $this->l('Position du badge (invisible / v3)'), [
                        'bottomright' => $this->l('En bas à droite'),
                        'bottomleft' => $this->l('En bas à gauche'),
                        'inline' => $this->l('Intégré au formulaire'),
                    ]),
                    $switch('WSRC_HIDE_BADGE', $this->l('Masquer le badge'), $this->l('Autorisé par Google à condition d\'afficher la mention « protégé par reCAPTCHA » avec les liens de confidentialité et conditions : le module l\'ajoute alors dans chaque formulaire.')),
                    $switch('WSRC_CHECK_HOSTNAME', $this->l('Contrôler le nom d\'hôte'), $this->l('Refuse les jetons émis sur un autre domaine que ceux de la boutique. À laisser désactivé avec les clés de test Google.')),
                    $switch('WSRC_FAIL_OPEN', $this->l('Laisser passer si Google est injoignable'), $this->l('Oui (recommandé) : en cas de panne ou de délai dépassé (5 s), les formulaires restent utilisables. Non : les envois sont bloqués tant que Google ne répond pas.')),
                ],
                'submit' => $submit,
            ],
        ];

        $formInputs = [];
        foreach (self::FORMS as $key => $def) {
            $label = $this->formLabel($key);
            $missing = $def['module'] !== null && !Module::isEnabled($def['module']);
            $formInputs[] = $switch(
                'WSRC_FORM_' . $def['conf'],
                $label,
                $missing ? sprintf($this->l('Module %s absent ou désactivé : protection inactive.'), $def['module']) : ''
            );
            $formInputs[] = [
                'type' => 'text',
                'name' => 'WSRC_SEL_' . $def['conf'],
                'label' => $this->l('Sélecteur CSS'),
                'desc' => sprintf($this->l('Par défaut : %s'), $def['selector']),
                'form_group_class' => 'wsrc-sub',
            ];
            $formInputs[] = [
                'type' => 'text',
                'name' => 'WSRC_ACTION_' . $def['conf'],
                'label' => $this->l('Action v3'),
                'class' => 'fixed-width-xl',
                'form_group_class' => 'wsrc-sub wsrc-v3-only',
            ];
        }
        $forms = [
            'form' => [
                'legend' => ['title' => $this->l('Formulaires protégés'), 'icon' => 'icon-list'],
                'description' => $this->l('Les sélecteurs par défaut conviennent au thème Classic et au thème Websource. Plusieurs sélecteurs peuvent être séparés par des virgules. La vérification côté serveur s\'applique quel que soit le sélecteur.'),
                'input' => $formInputs,
                'submit' => $submit,
            ],
        ];

        $exclusions = [
            'form' => [
                'legend' => ['title' => $this->l('Exclusions'), 'icon' => 'icon-unlock'],
                'input' => [
                    $switch('WSRC_EXCLUDE_LOGGED', $this->l('Ne pas protéger pour les clients connectés')),
                    [
                        'type' => 'textarea',
                        'name' => 'WSRC_IP_WHITELIST',
                        'label' => $this->l('Adresses IP autorisées'),
                        'desc' => sprintf($this->l('Une adresse ou un bloc CIDR par ligne (ex. 192.168.1.10, 10.0.0.0/8, 2001:db8::/32). Votre adresse actuelle : %s'), Tools::getRemoteAddr()),
                        'rows' => 5,
                        'cols' => 40,
                    ],
                    $switch('WSRC_EXCLUDE_MAINT_IP', $this->l('Ne pas protéger pour les IP de maintenance'), $this->l('Reprend les adresses saisies dans Paramètres de la boutique > Général > Maintenance.')),
                ],
                'submit' => $submit,
            ],
        ];

        $consent = [
            'form' => [
                'legend' => ['title' => $this->l('Consentement cookies'), 'icon' => 'icon-shield'],
                'description' => $this->l('reCAPTCHA est un dispositif de sécurité : son usage peut reposer sur l\'intérêt légitime, c\'est pourquoi cette option est désactivée par défaut. Si vous l\'activez, le script Google n\'est chargé qu\'après accord du visiteur (module Websource Cookie Consent ou événement JavaScript personnalisé) ; sans accord, le visiteur peut l\'autoriser depuis le formulaire.'),
                'input' => [
                    $switch('WSRC_CONSENT_REQUIRED', $this->l('Charger reCAPTCHA après consentement')),
                    $select('WSRC_CONSENT_CATEGORY', $this->l('Catégorie Websource Cookie Consent'), [
                        'services' => $this->l('Services'),
                        'preferences' => $this->l('Préférences'),
                        'analytics' => $this->l('Mesure d\'audience'),
                        'marketing' => $this->l('Marketing'),
                    ]),
                    [
                        'type' => 'text',
                        'name' => 'WSRC_CONSENT_EVENT',
                        'label' => $this->l('Événement JavaScript générique'),
                        'desc' => $this->l('Facultatif : nom d\'un événement déclenché sur document ou window par votre outil de consentement (ex. recaptcha:consent). Vous pouvez aussi définir window.wsrcConsentGranted = true.'),
                        'class' => 'fixed-width-xxl',
                    ],
                ],
                'submit' => $submit,
            ],
        ];

        $log = [
            'form' => [
                'legend' => ['title' => $this->l('Journal'), 'icon' => 'icon-file-text'],
                'input' => [
                    $switch('WSRC_LOG_ENABLED', $this->l('Journaliser les échecs'), $this->l('Date, formulaire, adresse IP anonymisée (/24), score et codes d\'erreur. Purge automatique après 30 jours. Les compteurs agrégés restent disponibles même désactivé.')),
                ],
                'submit' => $submit,
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->table = $this->name;
        $helper->identifier = 'id_module';
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        // URL complète (avec jeton) : fonctionne sur le contrôleur legacy (1.7.8) comme sur la route Symfony (8/9).
        $helper->currentIndex = $this->configUrl();
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->show_toolbar = false;
        $helper->submit_action = 'submitWsrcConfig';

        $values = [];
        foreach ($this->configKeys() as $key) {
            $values[$key] = Configuration::get($key);
        }
        $values['WSRC_SECRET_KEY'] = '';
        $values['WSRC_SECRET_CLEAR'] = 0;
        $helper->tpl_vars = ['fields_value' => $values];

        return $helper->generateForm([$general, $forms, $exclusions, $consent, $log]);
    }

    /**
     * Libellé d'un formulaire.
     *
     * @param string $key
     *
     * @return string
     */
    private function formLabel($key)
    {
        $labels = [
            'contact' => $this->l('Formulaire de contact'),
            'register' => $this->l('Création de compte'),
            'login' => $this->l('Connexion'),
            'password' => $this->l('Mot de passe oublié'),
            'newsletter' => $this->l('Newsletter (ps_emailsubscription)'),
            'wsnewsletter' => $this->l('Newsletter (Websource Newsletter)'),
            'review' => $this->l('Avis produits (productcomments)'),
            'guest' => $this->l('Commande invité (tunnel de commande)'),
        ];

        return isset($labels[$key]) ? $labels[$key] : $key;
    }

    /**
     * Statistiques 7 / 30 jours et dernières entrées du journal.
     *
     * @return string
     */
    private function renderDashboard()
    {
        $idShop = (int) $this->context->shop->id;
        $by7 = WsrcLog::totalsByForm($idShop, 7);
        $by30 = WsrcLog::totalsByForm($idShop, 30);
        $rows = [];
        foreach (array_keys(self::FORMS) as $key) {
            $rows[] = [
                'label' => $this->formLabel($key),
                'blocked7' => isset($by7[$key]) ? $by7[$key]['blocked'] : 0,
                'passed7' => isset($by7[$key]) ? $by7[$key]['passed'] : 0,
                'blocked30' => isset($by30[$key]) ? $by30[$key]['blocked'] : 0,
                'passed30' => isset($by30[$key]) ? $by30[$key]['passed'] : 0,
            ];
        }
        $logs = [];
        foreach (WsrcLog::latest($idShop, 50) as $row) {
            $logs[] = [
                'date' => $row['date_add'],
                'form' => $this->formLabel($row['form']),
                'ip' => $row['ip'],
                'score' => $row['score'] === null ? '' : $row['score'],
                'codes' => $row['error_codes'],
                'blocked' => (int) $row['blocked'],
            ];
        }

        $this->context->smarty->assign([
            'wsrc_total7' => WsrcLog::totals($idShop, 7),
            'wsrc_total30' => WsrcLog::totals($idShop, 30),
            'wsrc_rows' => $rows,
            'wsrc_logs' => $logs,
            'wsrc_log_enabled' => (bool) Configuration::get('WSRC_LOG_ENABLED'),
            'wsrc_action_url' => $this->configUrl(),
            'wsrc_test_url' => $this->configUrl(['wsrcAjax' => 'testToken']),
            'wsrc_module_path' => $this->_path,
            'wsrc_lang' => isset($this->context->language->iso_code) ? (string) $this->context->language->iso_code : 'fr',
            'wsrc_retention' => WsrcLog::RETENTION_DAYS,
        ]);

        return $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }
}
