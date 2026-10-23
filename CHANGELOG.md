# Changelog

Toutes les évolutions notables de ce module sont documentées dans ce fichier.

Le format s'appuie sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet suit le [versionnage sémantique](https://semver.org/lang/fr/).

## [Unreleased]

## [1.0.0] - 2026-10-23

### Ajouté
- Prise en charge de Google reCAPTCHA v2 « case à cocher », v2 invisible et v3 (score ; refus sans défi de rattrapage sous le seuil).
- Réglages : interrupteur « Protection active », clé du site, clé secrète masquée (jamais réaffichée, effaçable), seuil v3 (défaut 0,5), action v3 par formulaire, thème clair/sombre, taille normale/compacte, position du badge, masquage du badge avec mention légale Google, contrôle optionnel du nom d'hôte.
- Protection activable par formulaire : contact, création de compte, connexion, mot de passe oublié, newsletter (ps_emailsubscription et websourcenewsletter), avis produits (productcomments), création de compte invité dans le tunnel de commande.
- Vérification côté serveur via l'API `siteverify` (succès, hostname, action et score v3) avant le traitement du formulaire (hooks `actionSubmitAccountBefore`, `actionNewsletterRegistrationBefore`, `actionFrontControllerInitBefore`), avec blocage propre et message d'erreur traduit dans le formulaire.
- Injection automatique du widget par sélecteurs CSS configurables (valeurs par défaut pour les thèmes Classic et Websource ; à renseigner pour Hummingbird), compatible formulaires AJAX ; script Google chargé uniquement si un formulaire ciblé est présent, en `async defer`, dans la langue courante.
- Option de chargement après consentement cookies (Websource Cookie Consent avec catégorie configurable et événement `wsconsent:update`, événement JavaScript générique, bouton « Autoriser reCAPTCHA »), désactivée par défaut.
- Exclusions : clients connectés, adresses IP autorisées (IPv4/IPv6, CIDR accepté), IP de maintenance.
- Comportement configurable si Google est injoignable (délai de 5 s) : laisser passer ou bloquer.
- « Tester les clés » : test complet dans le navigateur (vrai jeton : paire de clés, type et domaine) et test rapide de connexion à Google (détection de la clé secrète de test publique).
- Statistiques quotidiennes des envois validés et bloqués (7 et 30 jours, par formulaire) et journal des échecs (IP anonymisée /24 en IPv4, /48 en IPv6, score, codes d'erreur, purge automatique après 30 jours, désactivable, bouton « Vider le journal »).
- Multiboutique : réglages selon le contexte de boutique, statistiques et journal par boutique.
- Compatibilité PrestaShop 1.7.8 à 9.x et PHP 7.2 à 8.5, sans override.
- Traductions française et anglaise.
- Documentation utilisateur (FR complète, EN abrégée).

[Unreleased]: https://github.com/Websource-fr/websourcerecaptcha/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Websource-fr/websourcerecaptcha/releases/tag/v1.0.0
