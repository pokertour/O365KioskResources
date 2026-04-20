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

### Stockage public (logos d’identité)

Les logos admin sont stockés sur le disque `public`. Après déploiement ou en local :

```bash
php artisan storage:link
```

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

- **Administration** → **Logo & titre** → section **Microsoft 365 (Azure)**
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

3. **Grant admin consent for {votre organisation}** sur les permissions ajoutées (obligatoire pour les droits *Application*).

Sans consentement administrateur, les appels token « client credentials » échoueront (403 ou erreurs d’authentification selon les cas).

### 4. Enregistrer les identifiants dans l’application

1. Ouvrez **Administration** → **Logo & titre**.
2. Renseignez `Tenant ID`, `Client ID`, `Client secret`.
3. Enregistrez.

Le jeton d’accès Graph est mis en cache côté application (environ 50 minutes). La sauvegarde de cette configuration invalide automatiquement le cache token.

### 5. Salles côté Microsoft 365

Pour chaque **salle** créée dans l’admin, renseignez l’**e-mail ressource Microsoft 365** (adresse SMTP de la boîte aux lettres de salle). Cette adresse doit :

- exister dans le **même locataire** que l’application Entra ID ;
- être une boîte ressource / salle dont le calendrier est exposé à Graph comme pour un utilisateur (`/users/{email}/...`).

### Dépannage rapide

| Symptôme | Piste |
|----------|--------|
| « Microsoft 365 n’est pas configuré » | Vérifier les champs Microsoft 365 (Azure) dans **Administration > Logo & titre**. |
| 401 / invalid_client | Mauvais `Client ID` / secret ou secret expiré. |
| 403 Insufficient privileges | Permission Graph manquante ou **admin consent** non accordé. |
| 404 sur l’utilisateur / calendrier | L’e-mail ressource n’existe pas dans le tenant ou n’est pas une boîte valide côté Graph. |

Documentation Microsoft : [Microsoft Graph permissions reference](https://learn.microsoft.com/en-us/graph/permissions-reference), [Get access without a user](https://learn.microsoft.com/en-us/graph/auth-v2-service-principal).

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
