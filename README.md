# sdk-voxar-auth — `voxar/auth-sdk`

SDK Laravel pour brancher une application sur **Voxar Auth** (le compte unique Voxar) en 5 minutes :
connexion SSO, déconnexion unique, comptes synchronisés, API simplifiée et fake pour les tests.

- **Voxar Auth** (`voxar-auth/`) est le serveur : pages de connexion, 2FA, comptes, API.
  Sa documentation : `../voxar-auth/docs/API.md`.
- **Ce SDK** est ce que chaque application (Chat, Mail, Drive…) installe pour parler à Voxar Auth.

---

## Sommaire

1. [Comment ça marche](#1-comment-ça-marche)
2. [Brancher une nouvelle application (pas à pas)](#2-brancher-une-nouvelle-application-pas-à-pas)
3. [Référence du SDK](#3-référence-du-sdk)
4. [Tests dans une application](#4-tests-dans-une-application)
5. [Dépannage](#5-dépannage)

---

## 1. Comment ça marche

1. Une personne non connectée ouvre l'application → la route `login` du SDK l'envoie sur Voxar Auth.
2. Elle se connecte sur Voxar Auth (2FA, e-mail confirmé) → Voxar Auth la renvoie sur
   `<APP_URL>/auth/callback` avec un code à usage unique.
3. Le SDK échange ce code contre son identité (appel serveur avec la clé de l'application),
   crée ou met à jour l'utilisateur local, ouvre la session et revient sur la page demandée.

Chaque application revient chez elle (Mail → Mail, Chat → Chat), une seule connexion suffit pour
toutes, et la déconnexion est unique. Détails : `../voxar-auth/docs/API.md`, section 1.

---

## 2. Brancher une nouvelle application (pas à pas)

Exemple : une application **Voxar Mail** (Laravel) servie sur `http://localhost:8003`.
En résumé : **1 commande sur Voxar Auth, 3 commandes et 1 ligne de code dans l'application.**

### Étape 1 — Installer le SDK (dans l'application)

`composer.json` de l'application :

```json
"repositories": {
    "voxar-auth-sdk": {
        "type": "path",
        "url": "../sdk-voxar-auth",
        "options": { "symlink": true }
    }
}
```

> En production / CI, remplacez le dépôt `path` par le dépôt Git :
> `{ "type": "vcs", "url": "git@github.com:<organisation>/sdk-voxar-auth.git" }`.

```bash
composer require voxar/auth-sdk:@dev
php artisan voxar-auth:install --client=mail
```

`voxar-auth:install` :

- publie `config/voxar-auth.php` ;
- crée la migration qui ajoute `auth_id` et `two_factor_enabled` à la table `users` (et rend `password` facultatif) ;
- ajoute `VOXAR_AUTH_URL`, `VOXAR_AUTH_CLIENT` et `VOXAR_AUTH_TOKEN` au `.env` ;
- affiche la commande exacte à lancer sur Voxar Auth (étape 2).

### Étape 2 — Créer la clé de l'application (sur Voxar Auth)

```bash
cd voxar-auth
php artisan api:token mail@voxar.chat --name=mail \
    --redirect=http://localhost:8003/auth/callback --create
```

Copiez la ligne `VOXAR_AUTH_TOKEN=…` affichée dans le `.env` de Mail.

> La clé n'est affichée **qu'une seule fois**. En cas de perte, on en génère une nouvelle avec
> `--revoke` (voir `../voxar-auth/docs/API.md`, section 3).

### Étape 3 — Le modèle `User`

```php
use Voxar\AuthSdk\Concerns\HasVoxarAccount;

class User extends Authenticatable
{
    use HasFactory, HasVoxarAccount, Notifiable;
}
```

C'est tout : à chaque connexion, le SDK retrouve l'utilisateur local par `auth_id` (ou, pour un
compte créé avant Voxar Auth, par son e-mail), le crée s'il n'existe pas, et met à jour son nom,
son e-mail et son état 2FA. Retirez `password` des attributs `Fillable`/`Hidden` et les traits
Fortify s'il y en avait.

### Étape 4 — Migrer

```bash
php artisan migrate
```

> **L'application avait déjà ses propres comptes ?** Exportez-les **avant** de supprimer la
> colonne `password` :
>
> ```bash
> php artisan voxar-auth:export-users                    # dans l'application
> php artisan users:import <chemin affiché>              # sur Voxar Auth
> ```
>
> Les mots de passe et la 2FA restent les mêmes ; chaque compte est relié automatiquement à la
> première connexion. Vous pourrez ensuite supprimer la colonne `password` et la table
> `password_reset_tokens` dans une migration.

### Étape 5 — Nettoyer et brancher l'interface

- Supprimez les anciennes routes/pages `login`, `register`, mot de passe oublié, 2FA : le SDK fournit
  les routes `login`, `logout` et `auth/callback`, et le middleware `auth` de Laravel redirige déjà
  les invités vers Voxar Auth.
- **Déconnexion** : un formulaire / lien `POST` vers la route `logout`
  (Inertia : `<Link href={logout()} method="post" as="button">`).
- **Inscription** : lien vers `login?screen=register` (option `email=` pour pré-remplir).
- **Mot de passe, 2FA, e-mail** : lien vers « Mon compte Voxar », avec retour vers l'application :

  ```php
  $user->voxarAccountUrl('security', route('settings'))   // profile | password | security | appearance
  ```

> **Inertia** : les redirections vers Voxar Auth sont des visites externes. Le SDK répond déjà
> `409 + X-Inertia-Location` aux requêtes Inertia sur `login` et `logout`. Pour vos propres
> redirections vers Voxar Auth, utilisez `Inertia::location($url)` (jamais `redirect()->away()`),
> et des balises `<a href>` plutôt que `<Link>` pour les liens directs vers Voxar Auth.

### Ce que le SDK fait automatiquement

| Fonction | Détail |
| -------- | ------ |
| Routes `login`, `auth/callback`, `logout` | Connexion SSO, protection `state`, déconnexion unique. |
| Création / liaison des utilisateurs | Par le trait `HasVoxarAccount` (personnalisable, voir [section 3](#3-référence-du-sdk)). |
| Identité à jour | Middleware ajouté au groupe `web` : rafraîchit nom, e-mail et 2FA toutes les 5 min, déconnecte si le compte Voxar a été supprimé. |
| Retour à la bonne page | Après connexion, retour à la page demandée au départ (sinon `VOXAR_AUTH_HOME`). |

### Variables d'environnement

```dotenv
APP_URL=http://localhost:8003
SERVER_PORT=8003

VOXAR_AUTH_URL=http://localhost:8002
VOXAR_AUTH_CLIENT=mail
VOXAR_AUTH_TOKEN=3|xxxxxxxx
# Facultatif :
# VOXAR_AUTH_HOME=/inbox        page après connexion si aucune page n'était demandée (défaut : /)
# VOXAR_AUTH_SYNC_EVERY=300     rafraîchissement de l'identité, en secondes (0 = désactivé)
# VOXAR_AUTH_TIMEOUT=10         délai des appels API, en secondes
# VOXAR_AUTH_USER_MODEL=App\Models\User
```

### Récapitulatif

```
[ ] composer require voxar/auth-sdk
[ ] php artisan voxar-auth:install --client=<app>
[ ] sur Voxar Auth : php artisan api:token … --name=<app> --redirect=<APP_URL>/auth/callback --create
[ ] VOXAR_AUTH_TOKEN collé dans .env ; APP_URL et SERVER_PORT cohérents
[ ] use HasVoxarAccount dans le modèle User
[ ] (comptes existants) voxar-auth:export-users puis users:import
[ ] php artisan migrate
[ ] anciennes routes/pages login, register, mot de passe supprimées
[ ] bouton de déconnexion → POST logout ; liens « Mon compte Voxar »
```

---

## 3. Référence du SDK

### Sur le modèle `User` (trait `HasVoxarAccount`)

| Méthode | Description |
| ------- | ----------- |
| `User::findOrCreateFromVoxar(Identity $identity)` | Utilisateur local d'un compte Voxar (appelé automatiquement à la connexion). |
| `$user->voxarAccountUrl($section = 'profile', $returnTo = null)` | Lien vers « Mon compte Voxar » (`profile`, `password`, `security`, `appearance`). |
| `$user->renameOnVoxar($name)` | Change le nom affiché, partout (Voxar Auth puis local). |
| `$user->refreshFromVoxar()` | Recharge l'identité ; `false` si le compte Voxar a été supprimé. |
| `$user->deleteVoxarAccount()` | Supprime le compte Voxar (toutes les applications perdent l'accès). |
| `$user->syncFromVoxar(Identity $identity)` | Copie une identité sur l'utilisateur local. |

Pour adapter les colonnes remplies, surchargez `voxarAttributes()` dans le modèle :

```php
protected function voxarAttributes(Identity $identity): array
{
    return [
        'auth_id' => $identity->id,
        'display_name' => $identity->name,     // colonne différente
        'email' => strtolower($identity->email),
    ];
}
```

Logique entièrement différente (autre modèle, équipes…) : remplacez le résolveur dans un service provider :

```php
VoxarAuth::resolveUsersUsing(fn (Identity $identity) => Member::forVoxar($identity));
```

### Façade `Voxar\AuthSdk\Facades\VoxarAuth`

| Méthode | Retour | Description |
| ------- | ------ | ----------- |
| `accountUrl(string $section = 'profile', ?string $returnTo = null)` | `string` | Page « Mon compte Voxar » avec lien de retour. |
| `logoutUrl(?string $returnTo = null)` | `string` | Déconnexion de Voxar Auth puis retour (adresse d'une application connue). |
| `user(string $id)` | `?Identity` | Identité à jour, `null` si le compte a été supprimé. |
| `findByEmail(string $email)` | `?Identity` | Un compte Voxar existe-t-il pour cette adresse ? |
| `rename(string $id, string $name)` | `Identity` | Change le nom affiché (partagé par toutes les applications). |
| `deleteUser(string $id)` | `void` | Supprime le compte Voxar. |
| `syncOnNextRequest()` | `void` | Force le rafraîchissement de l'identité à la prochaine requête (ex. avant d'envoyer vers « Mon compte Voxar »). |
| `resolveUsersUsing(Closure $fn)` | `void` | Remplace la création/liaison des utilisateurs par défaut. |
| `fake()` | `VoxarAuthFake` | Voxar Auth simulé pour les tests ([section 4](#4-tests-dans-une-application)). |
| `authorizeUrl(…)`, `exchangeCode(…)` | | Bas niveau, utilisés par les routes du SDK. |

### Organisations (comptes professionnels)

Les organisations vivent dans **Voxar Auth** et sont partagées par toutes les applications : membres,
rôles (`admin` / `member`), unités (arborescence, manager), invitations et suspensions. Chaque application
garde ses propres réglages (modules, rétention…) et désigne organisations, unités et personnes par leur **uuid**.

- `$identity->organization` : `?OrganizationMembership` (`id`, `name`, `role`, `organizationSuspended`,
  `memberSuspended`, `unitIds`, `isAdmin()`), `null` pour un compte personnel.
- Annuaire : `VoxarAuth::organization($uuid)` ou `VoxarAuth::organizationOf($userUuid)` →
  `['organization' => [id, name, suspended], 'units' => [[id, parent_id, name, manager_id]], 'members' => [[id, name, email, …, role, suspended, unit_ids]]]`.
- Modifications : `createOrganization`, `updateOrganization`, `deleteOrganization`, `createUnit`, `updateUnit`,
  `deleteUnit`, `saveMember($org, $user, ['role' => …, 'unit_ids' => […], 'suspended' => …])`, `removeMember`,
  `invitations`, `invite`, `resendInvitation`, `revokeInvitation`. Un refus (dernier administrateur, compte
  déjà dans une autre organisation…) lève `VoxarAuthException` (code 422) avec le motif.
- Les invitations sont envoyées par Voxar Auth ; le lien ouvre Voxar Auth, où la personne rejoint l’organisation.
- Les administrateurs d’une organisation la gèrent aussi depuis **Mon compte Voxar → Organisation**.
- Tests : `VoxarAuth::fake()` simule les organisations en mémoire (`withOrganization($annuaire)`,
  `assertOrganizationCall('saveMember', fn (...) => …)`, `assertNoOrganizationCalls()`).

Une application qui garde une copie locale (Voxar Chat, Voxar Agenda) la resynchronise depuis l’annuaire
(par exemple au plus une fois par minute et par organisation).

### Commandes Artisan du SDK (dans l'application)

| Commande | Rôle |
| -------- | ---- |
| `voxar-auth:install [--client=] [--url=]` | Config, migration `auth_id`, variables `.env`, et étapes suivantes. |
| `voxar-auth:export-users [--path=]` | Exporte les comptes existants (hash des mots de passe, 2FA) pour `users:import`. |

### `Voxar\AuthSdk\Identity` (objet en lecture seule)

| Propriété           | Type      | Exemple                                  |
| ------------------- | --------- | ---------------------------------------- |
| `id`                | `string`  | `01a1ea07-cfc6-7cac-9943-32223f784b5f` (stocké dans `auth_id`) |
| `name`              | `string`  | `Léa Martin`                             |
| `email`             | `string`  | `lea@example.com`                        |
| `emailVerified`     | `bool`    | `true` (toujours vrai après une connexion SSO) |
| `twoFactorEnabled`  | `bool`    | `false`                                  |
| `createdAt`         | `?string` | `2026-11-17T13:52:38+00:00`              |

`toArray()` renvoie la même forme que l'objet `user` de l'API.

### Erreurs

Toute réponse en échec ou un Voxar Auth injoignable lève
`Voxar\AuthSdk\Exceptions\VoxarAuthException` (son `getCode()` contient le statut HTTP).
Attrapez-la pour afficher un message plutôt qu'une page d'erreur :

```php
try {
    $user->renameOnVoxar($name);
} catch (VoxarAuthException $e) {
    report($e);
    return back()->with('error', 'Voxar Auth est indisponible, réessayez dans un instant.');
}
```

---

## 4. Tests dans une application

Dans `phpunit.xml` :

```xml
<env name="VOXAR_AUTH_URL" value="http://auth.test"/>
<env name="VOXAR_AUTH_CLIENT" value="mail"/>
<env name="VOXAR_AUTH_TOKEN" value="test-token"/>
<env name="VOXAR_AUTH_SYNC_EVERY" value="0"/>
```

Les tests habituels continuent d'utiliser `$this->actingAs($user)` : rien à changer.
Pour tout ce qui appelle Voxar Auth, utilisez le **fake** (aucune requête réseau) :

```php
use Voxar\AuthSdk\Facades\VoxarAuth;

public function test_renaming_updates_the_voxar_account(): void
{
    $user = User::factory()->create();               // la factory doit remplir auth_id (fake()->uuid())
    $voxar = VoxarAuth::fake()->withIdentity($user);

    $this->actingAs($user)->post('/settings/profile', ['name' => 'Nouveau nom']);

    $voxar->assertRenamed($user->auth_id, 'Nouveau nom');
}

public function test_sign_in_through_voxar_auth(): void
{
    $code = VoxarAuth::fake()->codeFor(['name' => 'Léa', 'email' => 'lea@example.com']);

    $this->withSession(['voxar-auth.state' => 'etat'])
        ->get(route('voxar-auth.callback', ['code' => $code, 'state' => 'etat']))
        ->assertRedirect('/');

    $this->assertAuthenticated();
}

public function test_voxar_auth_outage_is_handled(): void
{
    VoxarAuth::fake()->unavailable();                // chaque appel lève VoxarAuthException
    // …
}
```

| Méthode du fake | Rôle |
| --------------- | ---- |
| `withIdentity($user \| Identity \| array, …)` | Comptes connus du faux Voxar Auth. |
| `codeFor($identity)` | Code de connexion à passer à la route `voxar-auth.callback`. |
| `unavailable()` | Simule une panne. |
| `assertRenamed($id, ?$name)`, `assertNotRenamed()` | Vérifie les renommages. |
| `assertDeleted($id)`, `assertNotDeleted()` | Vérifie les suppressions. |

Exemples complets : `voxar-chat/tests/Feature/` (`SettingsTest`, `AccountDeletionTest`, `VoxarAuthTest`).

---

## 5. Dépannage

| Symptôme | Cause probable / solution |
| -------- | ------------------------- |
| `ERR_CONNECTION_REFUSED` après « Se connecter » | Voxar Auth ne tourne pas sur l'adresse de `VOXAR_AUTH_URL`. Vérifiez `SERVER_PORT=8002` dans `voxar-auth/.env` et relancez `composer run dev`. |
| 403 « La demande de connexion a expiré » | Le `state` ne correspond pas : session expirée, ou connexion commencée dans un autre onglet/navigateur. Recommencez depuis l'application. |
| 502 « La connexion via Voxar Auth a échoué » | L'échange du code a échoué : clé invalide/révoquée (401) ou Voxar Auth injoignable depuis le serveur. Détail dans `storage/logs/laravel.log`. |
| La page reste figée après connexion, il faut actualiser | Redirection vers un autre domaine pendant une requête Inertia : utilisez `Inertia::location()` (voir étape 5). |
| L'application crée un doublon d'utilisateur | Le résolveur ne retrouve pas l'utilisateur existant : vérifiez la recherche par `auth_id` puis par e-mail. |
| « Page expirée » (419) en passant d'une application à l'autre en local | Toutes les applications sur `localhost` partagent le cookie `XSRF-TOKEN` (les cookies ignorent le port). Actualisez la page, ou donnez un nom d'hôte par application : `http://auth.localhost:8002`, `http://chat.localhost:8001`… (même schéma qu'en production, où chaque application a son sous-domaine). |
| « Ajoutez le trait HasVoxarAccount au modèle User » | Le trait manque dans `App\Models\User` (ou `VOXAR_AUTH_USER_MODEL` pointe ailleurs). |
| « VOXAR_AUTH_TOKEN manquant » | Clé absente du `.env`, ou cache de configuration : `php artisan config:clear`. |

Côté serveur (400 « Application inconnue », port, clé) : `../voxar-auth/docs/API.md`, section 7.
