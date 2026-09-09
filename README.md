# Cit-Gest

Application web de gestion du patrimoine immobilier, des assets techniques et
des services municipaux — Commune de Gimont (Gers) et son intercommunalité.

> **État du projet** : socle technique (authentification, RBAC, structure,
> sécurité) — les modules fonctionnels (Interventions, Patrimoine, Assets,
> Énergie, Utilisateurs, Paramétrage) seront ajoutés dans les prochaines
> itérations, sur cette même base.

## 1. Stack technique

- **PHP 8.1+** en natif, sans framework (structure MVC maison), pour rester
  compatible avec un hébergement mutualisé où l'accès SSH/Composer n'est pas
  garanti.
- **MySQL / MariaDB** via PDO (requêtes préparées systématiques).
- **Aucune dépendance JS/CSS externe obligatoire** — CSS maison, JS vanilla.
  Chart.js sera ajouté (CDN ou fichier local) pour le module Énergie.
- Compatible Composer si celui-ci devient disponible sur l'hébergement
  (`composer.json` fourni), mais **non requis** pour faire fonctionner
  l'application : un autoloader PSR-4 maison est utilisé par défaut
  (`src/Core/Autoloader.php`).

## 2. Arborescence du projet

```
Cit-Gest/
├── public/                 # Document root du site (à pointer côté hébergeur)
│   ├── index.php           # Front controller unique
│   ├── .htaccess           # Réécriture d'URL + en-têtes de sécurité
│   └── assets/              # CSS / JS / images
├── src/
│   ├── Core/                # Briques techniques (Router, Auth, Rbac, Session, Csrf, Database...)
│   ├── Controllers/         # Contrôleurs HTTP
│   ├── Models/              # Accès aux données (PDO)
│   ├── Middlewares/          # Auth, Rbac
│   └── routes.php           # Déclaration centralisée des routes
├── templates/               # Vues PHP natives
├── database/
│   ├── migrations/          # Scripts SQL de création de schéma, numérotés
│   └── seeds/                # Données de départ (rôles, permissions)
├── bin/
│   └── create-admin.php     # Script CLI de création du 1er compte admin
├── config/
│   ├── config.php           # Chargement de la configuration
│   └── .env.example         # Modèle de fichier d'environnement
└── storage/logs/            # Logs applicatifs (hors accès web)
```

## 3. Installation sur l'hébergement mutualisé

1. **Cloner le dépôt** sur le serveur (ou déposer les fichiers via SFTP) :
   ```
   git clone https://github.com/SeblDev/Cit-Gest.git
   ```

2. **Pointer le document root du domaine/sous-domaine sur le dossier
   `public/`** (réglage habituel chez OVH, o2switch, etc., dans l'espace
   client). Si ce n'est vraiment pas possible, le `.htaccess` racine fourni
   redirige automatiquement le trafic vers `public/` en solution de repli.

3. **Créer la base de données MySQL** depuis l'espace client de
   l'hébergeur, puis exécuter dans l'ordre (via phpMyAdmin ou en ligne de
   commande) :
   ```
   database/migrations/001_create_socle.sql
   database/seeds/001_seed_roles_and_permissions.sql
   ```

4. **Configurer l'environnement** : copier `config/.env.example` en
   `config/.env` et renseigner les identifiants de connexion MySQL fournis
   par l'hébergeur, ainsi que `APP_URL`. **Ce fichier ne doit jamais être
   commité dans Git** (il est déjà exclu via `.gitignore`).

5. **Créer le premier compte administrateur** :
   - Si un accès SSH est disponible :
     ```
     php bin/create-admin.php "Votre Nom" admin@mairie-gimont.fr
     ```
   - Si aucun accès SSH n'est disponible (hébergement très restreint), on
     pourra prévoir une variante web temporaire protégée par un jeton — à
     me demander si c'est le cas de votre hébergement.

6. **Vérifier le certificat HTTPS** est actif sur le domaine, puis dans
   `public/.htaccess`, décommenter la ligne `Strict-Transport-Security` une
   fois HTTPS confirmé fonctionnel.

## 4. Sécurité déjà en place dans ce socle

- Sessions PHP durcies (`HttpOnly`, `Secure` si HTTPS détecté, `SameSite=Strict`),
  expiration par inactivité, régénération de l'ID de session à la connexion.
- Mots de passe hachés en `Argon2id` (repli automatique sur `Bcrypt` si
  l'extension Argon2 n'est pas compilée sur l'hébergement).
- Blocage temporaire après plusieurs échecs de connexion (anti brute-force).
- Jeton CSRF sur le formulaire de connexion (à répliquer sur tous les
  futurs formulaires modifiants via `Csrf::field()` / `Csrf::isValid()`).
- Toutes les requêtes SQL passent par PDO en requêtes préparées.
- RBAC vérifié côté serveur (`Rbac::authorize()`), jamais seulement par
  masquage d'un bouton côté client.
- Journalisation des actions sensibles dans `logs_audit` (connexions,
  échecs, et bientôt créations/modifications/suppressions métier).
- En-têtes de sécurité HTTP (`X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`).

## 5. Feuille de route (prochaines itérations)

1. Module **Interventions** (portail public de signalement + workflow interne).
2. Module **Patrimoine immobilier** (fiche à 4 volets).
3. Module **Assets & équipements techniques**.
4. Module **Relevés & sobriété énergétique** (+ Chart.js).
5. Écrans complets **Utilisateurs, rôles & matrice d'habilitation**.
6. **Formulaires dynamiques** & gestion des référentiels.

Chaque module réutilisera les briques du socle (`Router`, `Auth`, `Rbac`,
`Csrf`, `View`, `AuditLogger`) sans les dupliquer.
