# O365 Kiosk Resources

Application **Laravel** + **Livewire Flux** pour afficher et gérer le planning des **salles de réunion Microsoft 365** (ressources « boîte aux lettres de salle »). Elle fournit :

- un **back-office** (administrateurs) : salles, identité visuelle, liens kiosque sécurisés par token ;
- une **vue kiosque** par salle (URL avec token) pour consulter le jour et créer des réservations via **Microsoft Graph**.

L’intégration calendrier utilise le flux **OAuth2 client credentials** (application déléguée au niveau du locataire), sans connexion utilisateur interactive.

---

## Prérequis

- **PHP 8.3+** avec extensions habituelles Laravel (pdo, mbstring, openssl, etc.)
- **Composer**
- **Node.js** + npm (assets Vite / Tailwind / Flux)
- Une **base de données** (SQLite par défaut dans `.env.example`, ou MySQL/PostgreSQL)

---

## Installation locale

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Configurer la base dans `.env`, puis :

```bash
php artisan migrate
php artisan db:seed          # crée / met à jour l’admin (voir variables ADMIN_* ci‑dessous)
npm install
npm run build                # ou : npm run dev
php artisan serve
```

### Logo

Le logo est stocké sur le disque privé (`storage/app/private/branding`) et servi par la route `/branding/logo` avec une CSP de sandbox (les SVG ne peuvent pas exécuter de script). Aucun `storage:link` n’est nécessaire. La migration du 22/10/2026 déplace automatiquement un logo existant depuis le disque `public`.

### Production : sécurité recommandée

```dotenv
APP_ENV=production
APP_DEBUG=false
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
```

L’application ajoute ses en-têtes de sécurité (`X-Frame-Options`, `nosniff`, `Referrer-Policy`, HSTS en HTTPS). Les pages d’écran sont servies en `no-referrer`, `no-store` et `noindex` car leur URL contient le jeton d’accès.

### Paramètres & langues

Menu **Paramètres** de l’administration :

| Page | Contenu |
|------|---------|
| Général | Langue par défaut (FR/EN), fuseau horaire, format d’heure 24 h / 12 h |
| Écrans | Fréquence d’actualisation, boutons de réservation rapide, durée maximale, affichage de l’organisateur, message de bas d’écran |
| Apparence | Nom, logo, couleurs, thème des écrans |
| Microsoft 365 | Identifiants Graph (confirmation du mot de passe) |
| Administrateurs | Comptes ayant accès à l’administration |

Chaque administrateur peut choisir sa langue depuis son menu ; les visiteurs (page de connexion) via le sélecteur FR/EN. Les traductions sont dans `lang/fr.json` et `lang/fr/*.php` (clés en anglais).

### Planificateur (alertes et nettoyage)

La supervision des écrans (alerte quand un écran ne répond plus) et le nettoyage du journal d’activité (180 jours) reposent sur le planificateur Laravel :

```cron
* * * * * cd /chemin/vers/app && php artisan schedule:run >> /dev/null 2>&1
```

En local : `php artisan schedule:work`. Les alertes partent par e-mail (config `MAIL_*`) et/ou vers un canal Teams (Paramètres › Notifications).

### Installer un écran

Sur la tablette, ouvrez `/kiosk` : un code à 6 chiffres s’affiche. Dans l’admin, ouvrez la salle › onglet **Écran** › **Saisir un code**. L’écran bascule seul sur la salle. Le lien direct reste disponible dans le même onglet.

Le QR code affiché sur les écrans ouvre une vue téléphone signée (valable 30 min) : les téléphones doivent pouvoir joindre le serveur, et `APP_URL` / les proxys de confiance doivent refléter l’adresse publique.

### Compte administrateur (seed)

Variables optionnelles dans `.env` (voir `.env.example`) :

| Variable          | Description           |
|-------------------|-----------------------|
| `ADMIN_NAME`      | Nom affiché           |
| `ADMIN_EMAIL`     | E-mail de connexion   |
| `ADMIN_PASSWORD`  | Mot de passe          |

Le seeder force `is_admin = true` et l’e-mail vérifié pour ce compte.

---

## Configuration Microsoft Azure (Graph API)

La configuration Microsoft Graph se fait dans l’interface admin :

- **Configuration** → **Microsoft 365** (confirmation du mot de passe demandée)
- champs requis : `Tenant ID`, `Client ID`, `Client secret`

Le secret est stocké chiffré en base de données. Sans ces valeurs, l’UI admin indique que Microsoft 365 n’est pas configuré ; le reste de l’app peut continuer à fonctionner partiellement.

### 1. Inscrire une application dans Microsoft Entra ID (Azure AD)

1. Connectez-vous au portail **[Microsoft Entra admin center](https://entra.microsoft.com/)** (ou [Azure Portal](https://portal.azure.com/) → **Microsoft Entra ID**).
2. **Applications** → **App registrations** → **New registration**.
3. Nom libre (ex. « O365 Kiosk Resources »), comptes pris en charge : **Accounts in this organizational directory only** (single tenant).
4. Enregistrez. Notez :
   - **Application (client) ID** → champ `Client ID` dans l’app
   - **Directory (tenant) ID** → champ `Tenant ID` dans l’app

### 2. Créer un secret client

1. Dans l’application → **Certificates & secrets** → **New client secret**.
2. Copiez la **valeur** du secret immédiatement (elle ne réapparaît pas) → champ `Client secret` dans l’app.

### 3. API Microsoft Graph — permissions **Application** (pas « Delegated »)

1. **API permissions** → **Add a permission** → **Microsoft Graph** → **Application permissions**.
2. Ajoutez au minimum :
   - **`Calendars.ReadWrite`** — lecture et écriture des calendriers accessibles à l’application dans le locataire (création / suppression d’événements pour les boîtes ressources utilisées comme « utilisateur » Graph).

   Le code appelle des URL du type `https://graph.microsoft.com/v1.0/users/{adresseSMTP}/calendar/...` où `{adresseSMTP}` est l’**e-mail de la ressource salle** (boîte aux lettres de salle) saisi dans l’admin.

   - *(facultatif)* **`Place.Read.All`** — pour le bouton **Importer depuis Microsoft 365** de la page Salles.

3. **Grant admin consent for {votre organisation}** sur les permissions ajoutées (obligatoire pour les droits *Application*).

Sans consentement administrateur, les appels token « client credentials » échoueront (403 ou erreurs d’authentification selon les cas).

### 4. Enregistrer les identifiants dans l’application

1. Ouvrez **Configuration** → **Microsoft 365**.
2. Renseignez l’ID du locataire, l’ID d’application et le secret client.
3. Cliquez sur **Enregistrer et tester** : un jeton est demandé immédiatement pour valider la configuration.

Le jeton d’accès Graph est mis en cache côté application (environ 50 minutes). La sauvegarde de cette configuration invalide automatiquement le cache token.

### Connexion des administrateurs avec Microsoft (SSO)

Dans l’inscription d’application › **Authentication**, ajoutez une plateforme **Web** avec l’URI de redirection affichée dans **Paramètres › Microsoft 365** (`https://votre-domaine/auth/microsoft/callback`). La permission déléguée `User.Read` (présente par défaut) suffit. Activez ensuite « Se connecter avec Microsoft » : seuls les comptes existants (même adresse e-mail) peuvent se connecter. Les mots de passe ne peuvent être désactivés qu’après une première connexion Microsoft réussie.

### 5. Salles côté Microsoft 365

Pour chaque **salle** créée dans l’admin, renseignez l’**e-mail ressource Microsoft 365** (adresse SMTP de la boîte aux lettres de salle). Cette adresse doit :

- exister dans le **même locataire** que l’application Entra ID ;
- être une boîte ressource / salle dont le calendrier est exposé à Graph comme pour un utilisateur (`/users/{email}/...`).

### Dépannage rapide

| Symptôme | Piste |
|----------|--------|
| « Microsoft 365 n’est pas configuré » | Vérifier la page **Configuration > Microsoft 365**. |
| 401 / invalid_client | Mauvais `Client ID` / secret ou secret expiré. |
| 403 Insufficient privileges | Permission Graph manquante ou **admin consent** non accordé. |
| 404 sur l’utilisateur / calendrier | L’e-mail ressource n’existe pas dans le tenant ou n’est pas une boîte valide côté Graph. |

Documentation Microsoft : [Microsoft Graph permissions reference](https://learn.microsoft.com/en-us/graph/permissions-reference), [Get access without a user](https://learn.microsoft.com/en-us/graph/auth-v2-service-principal).

---

## Déploiement (Docker)

### Publier une version

Une image est construite et publiée sur GitHub Container Registry à chaque **tag de version** `X.X.X` :

```bash
git tag 1.0.0
git push origin 1.0.0
```

Le workflow `.github/workflows/release.yml` lance d’abord les tests (Pint + Pest), puis publie `ghcr.io/pokertour/o365kioskresources` avec les tags `1.0.0`, `1.0` et `latest`. La version est affichée dans l’administration (bas du menu, Paramètres › Général, `php artisan about`) ; une copie locale affiche `local-dev`.

> Au premier push, le paquet GHCR est privé : rendez-le public ou connectez le serveur à GHCR (`docker login ghcr.io`) depuis les paramètres du paquet sur GitHub.

### L’image

Basée sur **FrankenPHP (PHP 8.5)**, utilisateur non-root, port **8080**, healthcheck sur `/up`. Une seule image, trois rôles (argument de commande) :

| Rôle | Commande | Rôle |
|------|----------|------|
| `app` (défaut) | `frankenphp run` | Serveur web |
| `worker` | `php artisan queue:work` | Envoi des notifications (e-mail, Teams) |
| `scheduler` | `php artisan schedule:work` | Alertes écrans hors ligne, nettoyage |

Au démarrage : création des dossiers de `storage`, `php artisan optimize`, et migrations si `RUN_MIGRATIONS=true` (rôle `app` uniquement).

Variables principales :

| Variable | Description |
|----------|-------------|
| `APP_KEY` | **Obligatoire.** `docker run --rm ghcr.io/pokertour/o365kioskresources php artisan key:generate --show` |
| `APP_URL` | URL publique (`https://salles.example.com`) |
| `TRUSTED_PROXIES` | `*` ou IP/CIDR du reverse proxy (nécessaire pour HTTPS et les QR codes) |
| `RUN_MIGRATIONS` | `true` pour migrer au démarrage du conteneur `app` |
| `DB_*` | SQLite par défaut dans le volume (`/app/storage/database.sqlite`) ; MySQL/PostgreSQL supportés |
| `MAIL_*` | Serveur d’envoi des alertes |
| `SERVER_NAME` | `:8080` par défaut (HTTP derrière un proxy) ; mettre un domaine pour que FrankenPHP gère lui-même le HTTPS |

Le volume **`/app/storage`** contient la base SQLite, le logo et les sessions : à sauvegarder.

### Docker Compose

Voir [`compose.example.yaml`](compose.example.yaml) (app + worker + scheduler, volume partagé). Placez un reverse proxy HTTPS devant le port 8080 : le HTTPS est requis pour que les tablettes restent allumées (Wake Lock).

Construire l’image localement :

```bash
docker build --build-arg APP_VERSION=1.0.0 -t o365-kiosk .
```

---

## Développement

```bash
php artisan test
vendor/bin/pint
```

Frontend :

```bash
npm run dev
```

---

## Licence

Projet dérivé des conventions Laravel / Livewire Starter Kit ; licence MIT sauf mention contraire des dépendances.
