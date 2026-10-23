{**
 * Websource reCAPTCHA — statistiques et journal des échecs (page de configuration).
 *
 * @author    Websource
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 *}
<link rel="stylesheet" href="{$wsrc_module_path|escape:'html':'UTF-8'}views/css/admin.css">
<script src="{$wsrc_module_path|escape:'html':'UTF-8'}views/js/admin.js"></script>

<div class="panel wsrc-test" id="wsrc-test"
     data-url="{$wsrc_test_url|escape:'html':'UTF-8'}"
     data-lang="{$wsrc_lang|escape:'html':'UTF-8'}"
     data-msg-no-site-key="{l s='Renseignez d\'abord la clé du site.' mod='websourcerecaptcha'}"
     data-msg-tick="{l s='Cochez la case ci-dessous pour lancer la vérification.' mod='websourcerecaptcha'}"
     data-msg-running="{l s='Test en cours…' mod='websourcerecaptcha'}"
     data-msg-no-token="{l s='Aucun jeton obtenu de Google : vérifiez la clé du site, son type (v2 case à cocher, v2 invisible ou v3) et la liste des domaines autorisés dans la console reCAPTCHA.' mod='websourcerecaptcha'}"
     data-msg-load-error="{l s='Impossible de charger le script Google reCAPTCHA (connexion ou bloqueur de contenu).' mod='websourcerecaptcha'}"
     data-msg-server-error="{l s='Réponse inattendue du serveur.' mod='websourcerecaptcha'}">
  <div class="panel-heading">
    <i class="icon-check"></i> {l s='Tester les clés' mod='websourcerecaptcha'}
  </div>
  <p>{l s='Le test complet obtient un vrai jeton auprès de Google avec la clé du site et la version saisies ci-dessus, puis le fait vérifier par le serveur avec la clé secrète (celle saisie, sinon celle enregistrée). Il contrôle ainsi le couple de clés, leur type et le domaine autorisé, sans enregistrer la configuration.' mod='websourcerecaptcha'}</p>
  <div class="wsrc-test__widget"></div>
  <div class="wsrc-test__result" role="status" aria-live="polite"></div>
  <form method="post" action="{$wsrc_action_url|escape:'html':'UTF-8'}" class="wsrc-test__actions">
    <button type="button" class="btn btn-primary wsrc-test__run">
      <i class="icon-play"></i> {l s='Lancer le test complet' mod='websourcerecaptcha'}
    </button>
    <button type="submit" name="submitWsrcTest" value="1" class="btn btn-default">
      <i class="icon-signal"></i> {l s='Test rapide de connexion à Google' mod='websourcerecaptcha'}
    </button>
  </form>
</div>

<div class="panel wsrc-dashboard">
  <div class="panel-heading">
    <i class="icon-bar-chart"></i> {l s='Statistiques' mod='websourcerecaptcha'}
  </div>

  <div class="row wsrc-kpis">
    <div class="col-sm-6 col-lg-3">
      <div class="wsrc-kpi wsrc-kpi--blocked">
        <span class="wsrc-kpi__value">{$wsrc_total7.blocked|intval}</span>
        <span class="wsrc-kpi__label">{l s='Envois bloqués — 7 jours' mod='websourcerecaptcha'}</span>
      </div>
    </div>
    <div class="col-sm-6 col-lg-3">
      <div class="wsrc-kpi wsrc-kpi--blocked">
        <span class="wsrc-kpi__value">{$wsrc_total30.blocked|intval}</span>
        <span class="wsrc-kpi__label">{l s='Envois bloqués — 30 jours' mod='websourcerecaptcha'}</span>
      </div>
    </div>
    <div class="col-sm-6 col-lg-3">
      <div class="wsrc-kpi">
        <span class="wsrc-kpi__value">{$wsrc_total7.passed|intval}</span>
        <span class="wsrc-kpi__label">{l s='Envois validés — 7 jours' mod='websourcerecaptcha'}</span>
      </div>
    </div>
    <div class="col-sm-6 col-lg-3">
      <div class="wsrc-kpi">
        <span class="wsrc-kpi__value">{$wsrc_total30.passed|intval}</span>
        <span class="wsrc-kpi__label">{l s='Envois validés — 30 jours' mod='websourcerecaptcha'}</span>
      </div>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>{l s='Formulaire' mod='websourcerecaptcha'}</th>
          <th class="text-right">{l s='Bloqués 7 j' mod='websourcerecaptcha'}</th>
          <th class="text-right">{l s='Validés 7 j' mod='websourcerecaptcha'}</th>
          <th class="text-right">{l s='Bloqués 30 j' mod='websourcerecaptcha'}</th>
          <th class="text-right">{l s='Validés 30 j' mod='websourcerecaptcha'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach $wsrc_rows as $row}
          <tr>
            <td>{$row.label|escape:'html':'UTF-8'}</td>
            <td class="text-right">{$row.blocked7|intval}</td>
            <td class="text-right">{$row.passed7|intval}</td>
            <td class="text-right">{$row.blocked30|intval}</td>
            <td class="text-right">{$row.passed30|intval}</td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
</div>

<div class="panel wsrc-log">
  <div class="panel-heading">
    <i class="icon-list-alt"></i> {l s='Journal des échecs (50 dernières entrées)' mod='websourcerecaptcha'}
  </div>

  {if !$wsrc_log_enabled}
    <div class="alert alert-info">{l s='La journalisation détaillée est désactivée : seuls les compteurs sont mis à jour.' mod='websourcerecaptcha'}</div>
  {/if}

  {if $wsrc_logs}
    <div class="table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th>{l s='Date' mod='websourcerecaptcha'}</th>
            <th>{l s='Formulaire' mod='websourcerecaptcha'}</th>
            <th>{l s='IP (anonymisée)' mod='websourcerecaptcha'}</th>
            <th class="text-right">{l s='Score' mod='websourcerecaptcha'}</th>
            <th>{l s='Codes' mod='websourcerecaptcha'}</th>
            <th>{l s='Résultat' mod='websourcerecaptcha'}</th>
          </tr>
        </thead>
        <tbody>
          {foreach $wsrc_logs as $log}
            <tr>
              <td>{$log.date|escape:'html':'UTF-8'}</td>
              <td>{$log.form|escape:'html':'UTF-8'}</td>
              <td><code>{$log.ip|escape:'html':'UTF-8'}</code></td>
              <td class="text-right">{$log.score|escape:'html':'UTF-8'}</td>
              <td><code>{$log.codes|escape:'html':'UTF-8'}</code></td>
              <td>
                {if $log.blocked}
                  <span class="badge badge-danger wsrc-badge-blocked">{l s='Bloqué' mod='websourcerecaptcha'}</span>
                {else}
                  <span class="badge badge-warning wsrc-badge-passed">{l s='Laissé passer (Google injoignable)' mod='websourcerecaptcha'}</span>
                {/if}
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  {else}
    <p class="text-muted">{l s='Aucun échec enregistré.' mod='websourcerecaptcha'}</p>
  {/if}

  <p class="help-block">{l s='Les entrées de plus de %d jours sont supprimées automatiquement.' sprintf=[$wsrc_retention] mod='websourcerecaptcha'}</p>

  <form method="post" action="{$wsrc_action_url|escape:'html':'UTF-8'}" class="wsrc-clear-log">
    <button type="submit" name="submitWsrcClearLog" value="1" class="btn btn-default" onclick="return confirm('{l s='Vider le journal des échecs ?' mod='websourcerecaptcha' js=1}');">
      <i class="icon-trash"></i> {l s='Vider le journal' mod='websourcerecaptcha'}
    </button>
  </form>
</div>
