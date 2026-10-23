# Page websource.fr — Module PrestaShop gratuit : Websource reCAPTCHA

Contenu prêt à intégrer. Les éléments entre crochets `[ ]` sont des indications de mise en page, à ne pas publier.

---

## Métadonnées SEO

- **Balise title** (52 caractères) : `Module reCAPTCHA gratuit pour PrestaShop | Websource`
- **Meta description** (149 caractères) : `Protégez vos formulaires PrestaShop du spam avec Google reCAPTCHA v2, invisible ou v3. Module gratuit, vérification serveur, compatible PrestaShop 9.`
- **URL suggérée** : `/module-prestashop-recaptcha-gratuit` (page fille du catalogue `/modules-prestashop-gratuits` ; fil d'Ariane : Accueil > Modules PrestaShop gratuits > Websource reCAPTCHA)
- **Mot-clé principal** : module reCAPTCHA PrestaShop
- **Mots-clés secondaires** : anti-spam PrestaShop, captcha formulaire de contact PrestaShop, reCAPTCHA v3 PrestaShop, faux comptes clients PrestaShop

---

# Module PrestaShop gratuit : Websource reCAPTCHA

**Stoppez le spam et les faux comptes sur votre boutique PrestaShop, sans gêner vos clients.** Websource reCAPTCHA ajoute la protection Google reCAPTCHA à vos formulaires de contact, de création de compte, de connexion et de newsletter, et vérifie chaque envoi côté serveur. Gratuit, open source, compatible de PrestaShop 1.7.8 à 9.

[Bouton principal : Télécharger le module sur GitHub → https://github.com/Websource-fr/websourcerecaptcha/releases]

[Capture : docs/images/contact-form.png — formulaire de contact protégé]

## Pourquoi protéger vos formulaires ?

Les robots remplissent en continu les formulaires des boutiques en ligne : messages publicitaires dans le formulaire de contact, comptes clients fictifs, inscriptions newsletter avec des adresses inexistantes, tentatives de connexion avec des mots de passe volés. Chaque envoi automatisé coûte du temps de tri, pollue votre base clients et dégrade la délivrabilité de vos e-mails.

## Les bénéfices

- **Moins de spam à trier** : les messages automatisés sont bloqués avant d'arriver dans votre boîte de réception.
- **Une base clients propre** : fini les faux comptes et les adresses newsletter fictives qui faussent vos statistiques et pénalisent vos campagnes.
- **Des comptes clients mieux protégés** : les tentatives de connexion en masse sont freinées.
- **Un parcours client préservé** : avec reCAPTCHA invisible ou v3, vos clients n'ont rien à cocher.
- **Une installation sans risque** : aucune modification du cœur de PrestaShop, désinstallation propre.

## Fonctionnalités

| | |
|---|---|
| **Trois versions de reCAPTCHA** | v2 « Je ne suis pas un robot », v2 invisible, v3 avec seuil de score réglable (0,0 à 1,0) et action par formulaire. |
| **Formulaires protégés au choix** | Formulaire de contact, création de compte, connexion, mot de passe oublié, newsletter (ps_emailsubscription et Websource Newsletter), avis produits (productcomments), commande invité. |
| **Vérification côté serveur** | Chaque envoi est contrôlé auprès de Google avant d'être traité par PrestaShop (validité, action et score en v3, nom d'hôte en option). Un robot qui contourne le JavaScript est bloqué. |
| **Blocage propre** | Message d'erreur traduit dans le formulaire, sans page blanche, données saisies conservées quand PrestaShop le permet. |
| **Intégration automatique** | Insertion sans modifier le thème ; sélecteurs fournis pour les thèmes Classic et Websource, à renseigner pour Hummingbird ou tout autre thème ; formulaires AJAX gérés. |
| **Performance** | Script Google chargé de façon asynchrone, uniquement si un formulaire protégé est présent (au premier clic pour la newsletter et les avis), dans la langue du visiteur. |
| **Exclusions** | Clients connectés, liste d'IP autorisées (CIDR accepté), IP de maintenance. |
| **Personnalisation** | Thème clair ou sombre, taille compacte, position du badge, masquage du badge avec mention légale Google. |
| **Test et suivi** | « Lancer le test complet » (vrai jeton : paire de clés, type et domaine contrôlés) et « Test rapide de connexion à Google » ; statistiques des envois validés et bloqués sur 7 et 30 jours ; journal des échecs avec IP anonymisée, purge automatique après 30 jours. |
| **Respect de la vie privée** | Base légale d'intérêt légitime par défaut, option de chargement après consentement cookies (Websource Cookie Consent ou tout outil via un événement JavaScript), bouton « Autoriser reCAPTCHA » dans le formulaire. |
| **Fiabilité** | Comportement configurable si Google ne répond pas (laisser passer ou bloquer). |

[Capture : docs/images/config.png — page de configuration]

## Compatibilité

- **PrestaShop** 1.7.8, 8.x et 9.x (testé sur 9.2)
- **PHP** 7.2 à 8.5
- **Thèmes** : Classic et thème Websource (sélecteurs fournis), Hummingbird et thèmes personnalisés via sélecteurs CSS à renseigner
- **Multiboutique** : réglages par boutique, statistiques et journal par boutique
- **Langues** : français et anglais
- **Licence** : AFL-3.0, gratuit, code source ouvert

## Installation en 3 étapes

1. **Créez vos clés** sur la console Google reCAPTCHA (https://www.google.com/recaptcha/admin) en choisissant le type correspondant à la version souhaitée et en déclarant le domaine de votre boutique.
2. **Installez le module** : téléchargez le fichier zip sur GitHub, puis dans votre back-office *Modules > Gestionnaire de modules > Téléverser un module*.
3. **Configurez et testez** : collez vos clés, choisissez les formulaires à protéger, cliquez sur « Lancer le test complet », puis faites un essai en navigation privée.

Le guide utilisateur complet détaille chaque option, le paramétrage RGPD et le dépannage : https://github.com/Websource-fr/websourcerecaptcha/blob/main/docs/guide-utilisateur.md

## Questions fréquentes

### Le module est-il vraiment gratuit ?
Oui. Le module est distribué gratuitement sous licence open source AFL-3.0. Le service Google reCAPTCHA est lui aussi gratuit dans la limite des quotas fixés par Google, largement suffisants pour une boutique en ligne.

### Quelle version de reCAPTCHA choisir ?
La v2 invisible est un bon compromis pour la plupart des boutiques : le client ne voit un défi que si Google a un doute. La v3 n'affiche jamais de défi et attribue un score que vous comparez à un seuil ; un visiteur jugé suspect est refusé sans défi de rattrapage. La v2 « case à cocher » offre la protection la plus visible, utile si votre boutique est très ciblée par le spam.

### reCAPTCHA va-t-il faire baisser mes ventes ?
Le module permet de ne pas afficher reCAPTCHA aux clients déjà connectés, et les versions invisible et v3 ne demandent aucune action dans la grande majorité des cas. Le script n'est chargé que sur les pages contenant un formulaire protégé, sans effet sur les pages produits ou catégories.

### Est-ce compatible avec le RGPD ?
reCAPTCHA est une mesure de sécurité qui peut reposer sur l'intérêt légitime ; il doit être mentionné dans votre politique de confidentialité (le guide fournit un paragraphe type). Si vous préférez recueillir le consentement, une option charge le script uniquement après acceptation du bandeau cookies.

### Mon thème est personnalisé, le module fonctionnera-t-il ?
Oui. Le module s'insère dans les formulaires grâce à des sélecteurs CSS modifiables dans la configuration. Les valeurs par défaut couvrent les thèmes Classic et Websource ; pour Hummingbird ou un autre thème, il suffit d'indiquer le sélecteur du formulaire concerné, puis de tester avec le formulaire réel.

### Que se passe-t-il si Google est indisponible ?
Par défaut, les envois sont acceptés si Google ne répond pas dans les 5 secondes, afin de ne jamais bloquer vos clients. Vous pouvez choisir de bloquer les envois à la place.

## Téléchargez Websource reCAPTCHA

Le module est disponible gratuitement sur GitHub, avec sa documentation et son code source.

[Bouton principal : Télécharger sur GitHub → https://github.com/Websource-fr/websourcerecaptcha/releases]
[Lien secondaire : Signaler un problème → https://github.com/Websource-fr/websourcerecaptcha/issues]

### Besoin d'un accompagnement sur votre boutique ?

Websource conçoit, développe et maintient des boutiques PrestaShop : intégration de thème, développement de modules sur mesure, migration vers PrestaShop 9, performance et sécurité. Si vous souhaitez faire installer et paramétrer le module, ou plus largement sécuriser votre boutique, notre équipe peut vous accompagner.

[Bouton secondaire : Découvrir notre agence PrestaShop → https://www.websource.fr/agence-prestashop]

---

## Annexe : données structurées JSON-LD

À placer dans le `<head>` ou en fin de page.

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "SoftwareApplication",
  "name": "Websource reCAPTCHA",
  "description": "Module PrestaShop gratuit qui protège les formulaires (contact, compte, connexion, newsletter, avis) avec Google reCAPTCHA v2, v2 invisible ou v3, avec vérification côté serveur.",
  "applicationCategory": "BusinessApplication",
  "applicationSubCategory": "Module PrestaShop",
  "operatingSystem": "PrestaShop 1.7.8 - 9.x, PHP 7.2 - 8.5",
  "softwareVersion": "1.0.0",
  "datePublished": "2026-10-23",
  "inLanguage": ["fr", "en"],
  "license": "https://opensource.org/licenses/AFL-3.0",
  "url": "https://www.websource.fr/module-prestashop-recaptcha-gratuit",
  "downloadUrl": "https://github.com/Websource-fr/websourcerecaptcha/releases",
  "codeRepository": "https://github.com/Websource-fr/websourcerecaptcha",
  "isAccessibleForFree": true,
  "offers": {
    "@type": "Offer",
    "price": "0",
    "priceCurrency": "EUR",
    "availability": "https://schema.org/InStock"
  },
  "author": {
    "@type": "Organization",
    "name": "Websource",
    "url": "https://www.websource.fr"
  },
  "publisher": {
    "@type": "Organization",
    "name": "Websource",
    "url": "https://www.websource.fr"
  }
}
</script>
```

Optionnel : la FAQ ci-dessus peut aussi être balisée en `FAQPage` si la page l'affiche intégralement.
