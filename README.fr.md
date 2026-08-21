<div align="center">
    <h1>Laravel App Pairing</h1>
</div>

<p align="center">

[![Packagist Version](https://img.shields.io/packagist/v/neocode/laravel-app-pairing.svg?style=flat-square)](https://packagist.org/packages/neocode/laravel-app-pairing)
[![Total Downloads](https://img.shields.io/packagist/dt/neocode/laravel-app-pairing.svg?style=flat-square)](https://packagist.org/packages/neocode/laravel-app-pairing)
[![PHP Version](https://img.shields.io/packagist/php-v/neocode/laravel-app-pairing.svg?style=flat-square)](https://packagist.org/packages/neocode/laravel-app-pairing)
[![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20.svg?style=flat-square&logo=laravel)](https://laravel.com)
[![Tests](https://img.shields.io/github/actions/workflow/status/neocodesupport/laravel-app-pairing/tests.yml?branch=main&style=flat-square&label=tests&logo=github)](https://github.com/neocodesupport/laravel-app-pairing/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/neocode/laravel-app-pairing.svg?style=flat-square)](LICENSE.md)

</p>

<p align="center">
    <a href="README.md">English</a> | <strong>Français</strong>
</p>

Une couche générique, sécurisée et réutilisable d'**appairage et d'authentification inter-applications** pour les applications Laravel. Elle permet à une installation Laravel de découvrir, s'appairer, et authentifier cryptographiquement ses communications avec un nombre quelconque d'applications Laravel partenaires — sans rien savoir de ce que sont ces applications, ni des données qu'elles échangent.

Le package ne gère que cinq responsabilités : **identité**, **appairage**, **authentification**, **signature**, et **transport**. Il ne touche jamais à vos données métier ni à vos endpoints métier — vous continuez d'écrire vos propres contrôleurs et modèles, et vous les protégez simplement avec le middleware et le client HTTP signé fournis par ce package.

- [Concepts](#concepts)
- [Installation](#installation)
- [Identité de l'application](#identité-de-lapplication)
- [Le jeton d'amorçage](#le-jeton-damorçage-app_pairing_token)
- [La commande `pairing:key`](#la-commande-pairingkey)
- [Cycle de vie d'un appairage](#cycle-de-vie-dun-appairage)
  - [1. Demander un appairage](#1-demander-un-appairage)
  - [2. Recevoir une demande](#2-recevoir-une-demande)
  - [3. Accepter une demande](#3-accepter-une-demande)
  - [4. Rejeter une demande](#4-rejeter-une-demande)
  - [5. Révoquer un appairage](#5-révoquer-un-appairage)
  - [6. Faire tourner les clés](#6-faire-tourner-les-clés)
- [Envoyer des requêtes signées](#envoyer-des-requêtes-signées)
- [Protéger vos propres endpoints](#protéger-vos-propres-endpoints)
- [Tester la communication](#tester-la-communication)
- [Événements](#événements)
- [Journal d'activité](#journal-dactivité)
- [Modèle de sécurité](#modèle-de-sécurité)
- [Référence de configuration](#référence-de-configuration)
- [Modèle de données](#modèle-de-données)
- [Décisions de conception qui s'écartent d'une lecture littérale du cahier des charges](#décisions-de-conception-qui-sécartent-dune-lecture-littérale-du-cahier-des-charges)
- [Dépannage](#dépannage)

## Concepts

| Concept | Ce que c'est |
|---|---|
| **Identité de l'application** | Un `app_id` stable + des métadonnées descriptives (`name`, `base_url`, `environment`, `api_version`) identifiant *cette* installation. Deux environnements du même code (staging vs. production) ont des `app_id` différents. |
| **Jeton d'amorçage (`APP_PAIRING_TOKEN`)** | Un secret partagé utilisé **uniquement** pour autoriser la poignée de main d'appairage initiale. Il n'authentifie jamais le trafic normal et ne devient jamais une clé API permanente. |
| **Demande d'appairage** | Une proposition d'une application pour s'appairer avec une autre. Les demandes sont `pending` (en attente), `accepted` (acceptée), `rejected` (rejetée), ou `expired` (expirée). |
| **Appairage** | Une relation active et clée entre deux applications, identifiée par un `pairing_uuid` partagé par les deux côtés. États : `pending` (réservé pour un usage futur — voir [décisions de conception](#décisions-de-conception-qui-sécartent-dune-lecture-littérale-du-cahier-des-charges)), `active`, `revoked` (révoqué). |
| **Clé d'appairage** | Une paire de clés Ed25519. Chaque appairage suit les clés des deux applications, avec un historique complet des rotations. Les clés privées sont chiffrées au repos et ne quittent jamais l'application qui les a générées. |
| **Requête signée** | Chaque appel HTTP normal (post-appairage) est authentifié avec `app_id` + `pairing_id` + `timestamp` + `nonce` + un hash du corps, le tout couvert par une signature Ed25519 — jamais un jeton bearer. |

## Installation

```bash
composer require neocode/laravel-app-pairing
```

Publier le fichier de configuration :

```bash
php artisan vendor:publish --tag="pairing-config"
```

Les migrations du package sont chargées automatiquement — aucune publication requise. Lancez-les avec :

```bash
php artisan migrate
```

Si vous préférez avoir les fichiers de migration dans votre propre dossier `database/migrations` (par exemple pour les personnaliser), publiez-les explicitement à la place :

```bash
php artisan vendor:publish --tag="pairing-migrations"
```

## Identité de l'application

Ajoutez ces variables à votre `.env` (voir `config/pairing.php` pour toutes les options) :

```env
PAIRING_APP_ID=            # généré une seule fois, voir plus bas — ce n'est pas un secret
PAIRING_APP_NAME="Mon Application"
PAIRING_BASE_URL="${APP_URL}"
PAIRING_ENVIRONMENT="${APP_ENV}"
PAIRING_API_VERSION=v1
```

`PAIRING_APP_ID` doit être unique **par installation** — les déploiements production et staging du même code doivent utiliser des valeurs différentes. Vous n'avez pas besoin de le générer à la main : lancer `php artisan pairing:key` (voir [plus bas](#la-commande-pairingkey)) le provisionne automatiquement la première fois qu'il tourne, et n'y touche plus jamais ensuite.

Si votre application doit tirer son identité d'ailleurs que de la config/l'env (une ligne en base, un contexte multi-tenant, ...), liez plutôt votre propre implémentation du contrat :

```php
use Neocode\LaravelAppPairing\Contracts\ApplicationIdentity;

$this->app->bind(ApplicationIdentity::class, MyApplicationIdentity::class);
```

## Le jeton d'amorçage (`APP_PAIRING_TOKEN`)

```env
APP_PAIRING_TOKEN=
```

Ce secret prouve, uniquement pendant la poignée de main d'appairage, que le demandeur est autorisé à proposer un appairage. **La même valeur doit être configurée sur les deux applications** autorisées à amorcer un appairage entre elles. Il est envoyé dans l'en-tête `X-Pairing-Bootstrap-Token`, n'est jamais loggé, n'est jamais renvoyé dans une réponse, et n'est plus jamais utilisé une fois l'appairage `active`.

## La commande `pairing:key`

```bash
php artisan pairing:key            # génère un nouveau jeton et l'enregistre dans .env
php artisan pairing:key --show     # affiche le jeton actuel en clair
php artisan pairing:key --set      # définit le jeton de façon interactive (saisie masquée)
php artisan pairing:key --force    # ignore la confirmation d'écrasement
```

- **Chaque invocation** (quels que soient les flags) vérifie d'abord si `PAIRING_APP_ID` est défini dans `.env`, et génère et enregistre silencieusement un ULID s'il ne l'est pas. Cela n'arrive qu'une seule fois par installation — un `PAIRING_APP_ID` existant n'est jamais régénéré ni écrasé, par cette commande ou par un autre flag. Il n'y a pas de paire `--show`/`--set` pour lui car ce n'est pas un secret ; il est simplement affiché dans la console la seule fois où il est généré.
- La génération de `APP_PAIRING_TOKEN` utilise `random_bytes()` (un CSPRNG), jamais un algorithme personnalisé.
- Écraser un jeton existant demande confirmation sauf si `--force` est passé ; en exécution non interactive (`--no-interaction`) sans `--force`, la commande échoue proprement plutôt que de rester bloquée ou d'écraser silencieusement.
- `--set` n'accepte jamais le secret en argument de ligne de commande (`--set="..."` n'est délibérément pas supporté), car les valeurs d'arguments peuvent fuiter dans l'historique du shell, la liste des processus, et les logs CI. Il demande toujours une saisie masquée.
- Faire tourner `APP_PAIRING_TOKEN` ne révoque jamais les appairages déjà actifs — cela n'affecte que les futures poignées de main d'amorçage.

## Cycle de vie d'un appairage

Tout ceci passe par la façade `LaravelAppPairing` (ou en injectant `Neocode\LaravelAppPairing\LaravelAppPairing`), que vos propres contrôleurs/composants Livewire/commandes console appellent. Le package ne fournit aucune interface utilisateur.

### 1. Demander un appairage

```php
use Neocode\LaravelAppPairing\Facades\LaravelAppPairing;

$request = LaravelAppPairing::requestPairing('https://partner-app.example.com');
```

Cela génère une paire de clés Ed25519 pour cette nouvelle relation, stocke la moitié privée chiffrée, et envoie une requête authentifiée par le jeton d'amorçage au partenaire, contenant l'identité de cette application et sa clé publique. La demande démarre à `pending`.

### 2. Recevoir une demande

Les demandes entrantes arrivent automatiquement sur `POST {prefix}/pairing-requests` (voir la [configuration](#référence-de-configuration) pour le préfixe), authentifiées par le jeton d'amorçage, validées, vérifiées pour détecter les rejeux idempotents (par `request_id`) et les appairages déjà existants en conflit, puis stockées comme `PairingRequest` en `pending`. Écoutez `PairingRequested` pour notifier un administrateur, par exemple dans votre propre liste « demandes entrantes ».

### 3. Accepter une demande

```php
use Neocode\LaravelAppPairing\Models\PairingRequest;

$pairing = LaravelAppPairing::accept(PairingRequest::findOrFail($id));
```

Cela génère la propre paire de clés de cette application pour la relation, active l'appairage localement, et met en file la livraison de la réponse (la clé publique de cette application + le `pairing_id` partagé) vers le demandeur original. Le demandeur active son propre côté une fois qu'il a validé que cette réponse correspond à sa demande initiale.

### 4. Rejeter une demande

```php
LaravelAppPairing::reject(PairingRequest::findOrFail($id));
```

Aucun appairage n'est jamais créé pour une demande rejetée.

### 5. Révoquer un appairage

```php
use Neocode\LaravelAppPairing\Models\Pairing;

LaravelAppPairing::revoke(Pairing::findOrFail($id), reason: 'plus nécessaire');
```

La révocation est **immédiate et locale** — elle n'attend jamais que le partenaire soit joignable. La notification distante est mise en file et retentée indépendamment. Un appairage révoqué n'accepte plus jamais de requêtes signées ; réappairez-vous depuis zéro si la relation doit être restaurée.

### 6. Faire tourner les clés

```php
LaravelAppPairing::rotateKey(Pairing::findOrFail($id));
```

Génère une nouvelle paire de clés locale, l'utilise immédiatement pour les requêtes sortantes, et notifie le partenaire (signé avec la clé *précédente*, pour prouver la continuité). Le partenaire continue d'accepter l'ancienne clé pendant une courte fenêtre de grâce (`pairing.key_rotation_grace_period`) le temps que la rotation se propage, afin que les requêtes en vol ne soient pas rejetées à tort. La rotation ne révoque jamais l'appairage lui-même.

## Envoyer des requêtes signées

Une fois qu'un appairage est `active`, récupérez un client HTTP signé prêt à l'emploi pour lui :

```php
$client = LaravelAppPairing::clientFor($pairing);

$response = $client->post('/api/v1/orders', $payload);
$response = $client->get('/api/v1/orders/42');
```

Le client définit automatiquement `app_id`, `pairing_id`, `timestamp`, `nonce`, le hash du corps, et la signature Ed25519 ; applique votre politique de timeout et de retry configurée ; et impose HTTPS quand `pairing.https.required` est activé. Vous ne construisez jamais ces en-têtes vous-même, et c'est le **seul** endroit où vos endpoints métier et le protocole d'appairage se touchent — le package n'a aucune opinion sur ce que contient `$payload`.

## Protéger vos propres endpoints

Appliquez le middleware `pairing.auth` à toute route qui ne doit être appelable que par un partenaire authentifié :

```php
Route::middleware('pairing.auth')->post('/api/v1/orders', OrderController::class);
```

Le middleware vérifie que l'appairage existe et est actif, que `app_id`/`pairing_id` correspondent, que le timestamp et le nonce sont récents, et que la signature est valide — puis rend l'objet `Pairing` résolu disponible via `$request->attributes->get('pairing')`. Votre contrôleur ne réimplémente jamais rien de tout ça.

## Tester la communication

```php
$result = LaravelAppPairing::testCommunication($pairing);

// [
//     'status' => 'success',
//     'pairing_id' => '...',
//     'remote_application' => ['app_id' => '...', 'name' => '...', 'api_version' => '...'],
//     'https' => true,
//     'authentication' => 'valid',
//     'signature' => 'valid',
//     'response_time_ms' => 42,
// ]
```

Ceci exerce le vrai protocole signé de bout en bout (pas seulement un HTTP 200) contre un endpoint dédié de santé/authentification, et renvoie un résultat structuré que vous pouvez afficher dans votre propre interface « tester la connexion » — y compris un résultat `status: 'failed'` géré proprement (avec un code `error`) pour les timeouts, hôtes injoignables, ou échecs d'authentification, plutôt que de lever une exception.

## Événements

| Événement | Déclenché quand |
|---|---|
| `PairingRequested` | Une demande d'appairage entrante est reçue. |
| `PairingAccepted` | Un appairage devient actif (des deux côtés). |
| `PairingRejected` | Une demande d'appairage est rejetée (des deux côtés). |
| `PairingRevoked` | Un appairage est révoqué (localement ou sur notification du partenaire). |
| `PairingKeyRotationRequested` | Une rotation de clé est sur le point de démarrer. |
| `PairingKeyRotated` | Une nouvelle clé devient active pour un appairage (rotation locale ou notification de rotation entrante). |
| `CommunicationTested` | Un test de communication se termine, avec succès ou non. |
| `PairingAuthenticationFailed` | Une requête signée entrante échoue à l'authentification, avec le code de raison. |

## Journal d'activité

Quand [`spatie/laravel-activitylog`](https://github.com/spatie/laravel-activitylog) est configuré (il est livré comme dépendance de ce package), les événements suivants sont journalisés sous le nom de log `pairing` (configurable) : `pairing_requested`, `pairing_accepted`, `pairing_rejected`, `pairing_revoked`, `pairing_key_rotated`, `communication_tested`, `pairing_authentication_failed`, `pairing_request_expired`. Le contexte journalisé inclut toujours l'id de l'appairage/de la demande et les métadonnées de l'application distante — **jamais** `APP_PAIRING_TOKEN`, `APP_KEY`, les clés privées, les signatures complètes, ou les en-têtes de type `Authorization`. Le trafic métier par requête n'est volontairement pas journalisé ici ; utilisez vos logs Laravel/infrastructure habituels pour cela.

Désactivez-le entièrement avec `PAIRING_ACTIVITY_LOG_ENABLED=false`.

> **Note MySQL/PostgreSQL** : les modèles de ce package utilisent des clés
> primaires ULID (chaînes), mais la migration standard de
> `spatie/laravel-activitylog` type `subject_id` en bigint non signé, ce qui
> **tronque les ULID sur les moteurs de base de données stricts** (SQLite
> tolère silencieusement cela, c'est pourquoi ce genre de bug ne se manifeste
> généralement que sur des bases de données proches de la production). Vous
> n'avez rien à faire vous-même à ce sujet : ce package fournit sa propre
> migration `activity_log` qui crée la table avec un morph `subject`
> compatible ULID si elle n'existe pas encore, ou élargit `subject_id` en
> toute sécurité si la table existe déjà — y compris si votre application a
> déjà `spatie/laravel-activitylog` installé et migré indépendamment de ce
> package (pour votre propre journalisation d'activité, sans rapport). La
> migration de ce package est volontairement datée loin dans le futur pour
> qu'elle s'exécute toujours en dernier dans n'importe quel lot de
> migrations, après toute migration que votre propre application fournit
> pour la même table, afin qu'elle voie toujours l'état final plutôt que de
> le courser. Le morph `causer` est laissé intact dans tous les cas (il reste
> généralement un id utilisateur auto-incrémenté, ce qui est très bien).

## Modèle de sécurité

- **Cryptographie** : Ed25519 via libsodium (`sodium_crypto_sign_keypair`/`sodium_crypto_sign_detached`/`sodium_crypto_sign_verify_detached`). Aucune primitive cryptographique personnalisée n'est implémentée nulle part dans ce package.
- **Les clés privées** sont chiffrées au repos avec le chiffreur propre de Laravel (un cast Eloquent `encrypted`, adossé à `APP_KEY`), stockées dans une colonne `TEXT`, jamais journalisées, jamais sérialisées dans un payload de job en file, jamais renvoyées par un endpoint, et masquées de la sérialisation array/JSON des modèles. **Protéger `APP_KEY` est donc critique** — quiconque l'obtient avec la base de données peut déchiffrer toutes les clés privées stockées.
- **La représentation canonique signée** couvre `METHOD`, `PATH`, `TIMESTAMP`, `NONCE`, `SHA-256(BODY)`, `APP_ID`, et `PAIRING_ID` — jamais le corps seul, et jamais des en-têtes qui ne font pas explicitement partie de cette représentation.
- **Anti-rejeu** : chaque requête signée porte un timestamp (vérifié contre `pairing.signature_ttl`) et un nonce (mémorisé pour la même durée via le `NonceStore` adossé au cache, remplaçable via le contrat `NonceStore`). Un nonce réutilisé est rejeté.
- **L'authentification d'amorçage et l'authentification normale sont deux mécanismes totalement séparés** — `APP_PAIRING_TOKEN` n'apparaît jamais que sur les endpoints de poignée de main protégés par `pairing.bootstrap` ; tous les autres endpoints utilisent `pairing.auth` (signatures Ed25519).
- **HTTPS** est imposé par les deux middlewares dès que `pairing.https.required` est vrai (valeur par défaut).
- **Idempotence** : les demandes d'appairage, acceptations, rejets, révocations, et rotations sont tous sûrs à rejouer — associés par `request_id` (demandes) ou par l'état courant (révocation/rotation sont des no-ops une fois déjà appliqués).

## Référence de configuration

Voir le fichier `config/pairing.php`, entièrement commenté, pour chaque clé. Les points essentiels :

| Clé | Rôle |
|---|---|
| `identity.*` | `app_id`, `name`, `base_url`, `environment`, `api_version` de cette installation. |
| `bootstrap_token` | `APP_PAIRING_TOKEN`. |
| `https.required` | Rejeter le trafic d'appairage non-HTTPS (défaut `true`). |
| `routes.prefix` / `routes.version` / `routes.middleware` | Où les propres routes du package sont montées. |
| `signature_ttl` | Fenêtre de validité du timestamp/nonce, en secondes (défaut 300). |
| `pairing_request_ttl` | Combien de temps une demande d'appairage sans réponse reste `pending` avant que `pairing:prune-requests` ne l'expire. |
| `key_rotation_grace_period` | Combien de temps une clé sortie de rotation est encore acceptée, en secondes. |
| `http.*` | Timeout, timeout de connexion, nombre de tentatives/délai pour le client signé et le client d'amorçage. |
| `queue.*` | Connexion/queue/politique de tentatives pour les notifications de révocation et de rotation. |
| `activity_log.*` | Activer/désactiver et nommer le canal du journal d'activité. |

Lancez `php artisan pairing:prune-requests` sur une planification (ou laissez la propre entrée de planification quotidienne du package le faire) pour expirer les demandes en attente devenues obsolètes.

## Modèle de données

| Table | Rôle |
|---|---|
| `pairing_applications` | Applications connues — exactement une ligne par installation est marquée `is_local` ; chaque partenaire a sa propre ligne. |
| `pairing_requests` | L'enregistrement de la poignée de main : `request_id`, direction, métadonnées distantes, statut (`pending`/`accepted`/`rejected`/`expired`). |
| `pairing_pairings` | La relation elle-même : `pairing_uuid` partagé, les deux applications, statut (`pending`/`active`/`revoked`), horodatages. |
| `pairing_keys` | Historique complet des clés par appairage, marqué `owner` (`local`/`remote`) et `status` (`active`/`revoked`), pour que les rotations soient auditables. |

## Décisions de conception qui s'écartent d'une lecture littérale du cahier des charges

- **Le statut `pending` du modèle `Pairing` n'est actuellement pas atteignable dans le flux livré.** Une ligne `Pairing` est créée en `pending` au moment où une demande sortante est envoyée (pour que sa clé locale générée ait un endroit où vivre avant que la relation ne soit confirmée), et est supprimée si cette demande est rejetée ou expire. Elle ne devient jamais visible autrement qu'en `active`. Cela garde les demandes d'appairage et les appairages actifs proprement séparés, comme requis, sans état intermédiaire flottant à gérer.
- **Le matériel de clé privée propre à un appairage n'est jamais supprimé à la révocation**, seulement marqué `revoked` — cela permet à la notification de révocation mise en file d'être quand même signée correctement même si elle s'exécute après que l'état local a déjà basculé, sans affaiblir aucune garantie d'authentification (les requêtes entrantes d'un appairage révoqué sont toujours rejetées par `pairing.auth` quel que soit le statut de la clé).
- **Une notification de révocation entrante ne met pas elle-même en file une nouvelle notification de révocation sortante.** Le côté qui a révoqué en premier le sait déjà ; le lui renvoyer en écho la ferait juste rebondir indéfiniment.

## Dépannage

- **« No PAIRING_APP_ID is configured »** — lancez `php artisan pairing:key`, qui le provisionne automatiquement (voir [Identité de l'application](#identité-de-lapplication)), ou liez un `ApplicationIdentity` personnalisé.
- **« No APP_PAIRING_TOKEN is configured »** — lancez `php artisan pairing:key` sur les deux applications et assurez-vous qu'ils correspondent.
- **`pairing_not_active` sur chaque requête** — l'appairage a été révoqué, ou vous visez le mauvais `pairing_id`/`app_id`.
- **`nonce_reused`** — votre client a retenté une requête dont le nonce avait déjà été consommé ; le client signé génère automatiquement un nonce frais à chaque tentative, donc cela indique généralement un client extérieur à ce package qui réutilise un payload signé.
- **`timestamp_out_of_window`** — vérifiez le décalage d'horloge entre les deux applications ; la fenêtre acceptable est `pairing.signature_ttl` secondes.

## Changelog

Voir le [CHANGELOG](CHANGELOG.md) pour plus d'informations sur les derniers changements.

## Contribuer

Merci d'envisager de contribuer à Laravel App Pairing ! Consultez notre [guide de contribution](.github/CONTRIBUTING.md) pour démarrer.

## Vulnérabilités de sécurité

Consultez notre [politique de sécurité](.github/SECURITY.md) pour savoir comment signaler une vulnérabilité.

## Crédits

- [Armel Meledje](https://github.com/meledjearmel)
- [Tous les contributeurs](../../contributors)

## Licence

Laravel App Pairing est un logiciel open-source distribué sous [licence MIT](LICENSE.md).
