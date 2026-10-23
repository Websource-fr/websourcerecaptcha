# Websource reCAPTCHA — protection anti-spam des formulaires PrestaShop

[![Licence AFL-3.0](https://img.shields.io/badge/licence-AFL--3.0-blue.svg)](LICENSE.md)
[![PrestaShop 1.7.8 – 9.x](https://img.shields.io/badge/PrestaShop-1.7.8%20%E2%80%93%209.x-df0067.svg)](https://www.prestashop.com)
[![PHP 7.2 – 8.5](https://img.shields.io/badge/PHP-7.2%20%E2%80%93%208.5-777bb4.svg)](https://www.php.net)

Module **gratuit** édité par [Websource](https://www.websource.fr) : ajoute **Google reCAPTCHA** (v2 case à cocher, v2 invisible ou v3) aux formulaires de votre boutique et **vérifie chaque envoi côté serveur**. Aucune override, aucune dépendance externe.

*English summary [below](#english).*

## Fonctionnalités

| | |
|---|---|
| **Versions** | `v2_checkbox` (case « Je ne suis pas un robot »), `v2_invisible`, `v3` (score, seuil 0,0–1,0, défaut 0,5, action par formulaire ; score bas = refus sans défi de rattrapage). |
| **Formulaires** | Contact (`contactform`), création de compte, connexion, mot de passe oublié, newsletter `ps_emailsubscription`, newsletter `websourcenewsletter`, avis produits (`productcomments`), compte invité du tunnel. Chacun activable séparément. |
| **Vérification serveur** | Appel `siteverify` avant le traitement du formulaire (succès, action et score en v3, hostname en option), via hooks natifs ou `actionFrontControllerInitBefore`. En cas d'échec : message d'erreur traduit dans le formulaire, sans page blanche ni exception. |
| **Intégration front** | Injection automatique via sélecteurs CSS configurables (défauts pour les thèmes Classic et Websource ; Hummingbird : à régler en BO), formulaires AJAX gérés, script Google chargé en `async defer` uniquement si un formulaire ciblé est présent, dans la langue courante. |
| **Apparence** | Thème clair/sombre, taille normale/compacte, position du badge (bas droite, bas gauche, intégré), masquage du badge avec mention légale Google. |
| **Exclusions** | Clients connectés, IP autorisées (une par ligne, CIDR accepté), IP de maintenance. |
| **Cookies / RGPD** | Option « Charger reCAPTCHA après consentement » (Websource Cookie Consent, catégorie configurable, événement `wsconsent:update`, ou événement JavaScript générique), désactivée par défaut (intérêt légitime). |
| **Administration** | « Tester les clés » : test complet navigateur (vrai jeton : paire de clés, type, domaine) et test rapide serveur (connexion à Google, clé de test détectée). Statistiques quotidiennes validés/bloqués sur 7 et 30 jours, journal des échecs (IP anonymisée /24 IPv4 ou /48 IPv6, score, codes, purge auto 30 jours, bouton « Vider le journal »). Multiboutique. |
| **Fail-safe** | Si Google ne répond pas (5 s) : laisser passer (défaut) ou bloquer. |
| **Sécurité** | Clé secrète jamais réaffichée ni exposée au front ou dans les journaux, jeton CSRF admin, requêtes échappées. |

## Captures

| Configuration | Formulaire protégé | Statistiques |
|---|---|---|
| ![Configuration](docs/images/config.png) | ![Formulaire de contact](docs/images/contact-form.png) | ![Statistiques](docs/images/stats.png) |

## Installation

1. Télécharger `websourcerecaptcha-x.y.z.zip` depuis les [Releases](https://github.com/Websource-fr/websourcerecaptcha/releases) (ne pas décompresser).
2. Back-office : **Modules > Gestionnaire de modules > Téléverser un module**, déposer le zip.
3. Cliquer sur **Configurer**.

Installation manuelle : copier le dossier dans `modules/websourcerecaptcha/`, puis installer depuis le gestionnaire de modules.

Tant que les clés ne sont pas renseignées, le module ne bloque aucun envoi.

## Configuration rapide

1. Créer une paire de clés sur <https://www.google.com/recaptcha/admin> — **le type de clé doit correspondre à la version choisie** dans le module — et déclarer le domaine de la boutique.
2. Choisir la version, coller la **clé du site** et la **clé secrète** (format `6Lxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`).
3. Choisir les formulaires à protéger ; renseigner les sélecteurs CSS si le thème n'est ni Classic ni Websource (Hummingbird compris).
4. **Enregistrer**, cliquer sur **Lancer le test complet** (bloc « Tester les clés »), puis tester chaque formulaire en navigation privée.
5. Vider le cache : **Paramètres avancés > Performances**.

Valeurs conseillées : v2 case à cocher (défaut) ou v2 invisible, v3 seuil 0,5 ; clients connectés exclus ; « Laisser passer si Google est injoignable » ; journal activé.

## Documentation

- [Guide utilisateur complet (FR)](docs/guide-utilisateur.md) : choix de version, création des clés, chaque option, RGPD (paragraphe type pour la politique de confidentialité), règles Google sur le badge, dépannage.
- [User guide (EN, short)](docs/user-guide.en.md)

## Désinstallation

Supprime la configuration (dont les clés), les tables du journal et des statistiques, et les hooks. Aucun fichier du cœur n'est modifié.

## Limites connues

- Formulaire envoyé par JavaScript sans événement `submit` : pas de jeton, envoi refusé côté serveur.
- Compte invité sous PS 1.7.8 : la saisie est perdue en cas d'échec (conservée en 8/9).
- v2 case à cocher : case réinitialisée ~1,5 s après un envoi AJAX (jeton à usage unique).

## Support et contribution

- Bugs et suggestions : [Issues GitHub](https://github.com/Websource-fr/websourcerecaptcha/issues) — indiquez les versions de PrestaShop et PHP, le thème, la version reCAPTCHA utilisée et les codes d'erreur du journal. **Ne publiez jamais votre clé secrète.**
- Contributions : les pull requests sont les bienvenues (une fonctionnalité ou correction par PR, compatibilité PHP 7.2 conservée, pas d'override).
- Accompagnement professionnel (intégration, thème sur mesure, développement) : [Websource, agence PrestaShop](https://www.websource.fr/agence-prestashop).

## Licence

[AFL-3.0](LICENSE.md) — © [Websource](https://www.websource.fr)

---

## English

**Websource reCAPTCHA** is a free PrestaShop module (1.7.8 to 9.x, PHP 7.2 to 8.5) that protects your shop forms with Google reCAPTCHA **v2 checkbox, v2 invisible or v3**, with **server-side verification** of every submission.

- Protected forms: contact, account creation, login, password reset, newsletter (`ps_emailsubscription`, `websourcenewsletter`), product reviews (`productcomments`), guest checkout — each one optional.
- Configurable CSS selectors (defaults for Classic and Websource themes), AJAX forms supported, Google script loaded only where needed.
- Exclusions (logged-in customers, allowed IPs with CIDR, maintenance IPs), optional loading after cookie consent, fail-safe if Google is unreachable.
- "Test keys": full browser test (real token: key pair, type, domain) and quick server test; validated/blocked statistics over 7/30 days; failure log with anonymised IPs (auto-purged after 30 days).
- Hummingbird and custom themes: set the CSS selectors in the back office.
- No core override, no external dependency.

**Install**: download the zip from [Releases](https://github.com/Websource-fr/websourcerecaptcha/releases), then **Modules > Module Manager > Upload a module**. Create keys at <https://www.google.com/recaptcha/admin> (key type must match the selected version). See the [English user guide](docs/user-guide.en.md).

License AFL-3.0 — made by [Websource](https://www.websource.fr), PrestaShop agency.
