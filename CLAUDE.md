# Documentaliste — plugin GLPI

Plugin GLPI qui produit une **description synthétique de chaque document** (images avec OCR, Word, Excel, PDF…),
stockée en base, réutilisée pour la recherche, les notifications et par d'autres plugins
(ex. : bloquer un mot de passe collé dans un ticket).

- Cibles : **GLPI 11.0.x (PHP ≥ 8.2) et GLPI 12.0.x (PHP ≥ 8.3)**. Clé : `documentaliste`. Namespace : `GlpiPlugin\Documentaliste`.
- Code du plugin : `plugins/documentaliste/`. Docs : `docs/architecture.md`, `docs/roadmap.md`.
- Clones **en lecture seule** (ignorés par git), servant de référence : `glpi-src/` = GLPI 12.0.2, `glpi-src-11/` = GLPI 11.0.13.
  **Toujours vérifier une API, un hook ou une signature dans LES DEUX clones avant de l'utiliser.**
  Hooks : `glpi-src*/src/Glpi/Plugin/Hooks.php`. Plugin exemple : `glpi-src*/tests/fixtures/plugins/tester/`.

## 🔒 Règle absolue : aucune donnée ne sort du serveur

- Le contenu des documents, le texte extrait et les descriptions ne doivent **jamais** quitter le serveur GLPI :
  ni API cloud, ni appel HTTP, ni courriel, ni journal distant.
- Tous les traitements sont locaux : extraction PHP, OCR **Tesseract** (binaire local), et plus tard un LLM
  **sur le même serveur uniquement**.
- Garde-fou automatique : `tests/Unit/NoNetworkEgressTest.php` échoue sur toute primitive réseau ou client HTTP,
  et sur tout programme externe hors liste blanche (aujourd'hui : `tesseract`). Ne jamais affaiblir ce test pour
  faire passer une fonctionnalité : demander d'abord à l'utilisateur.
- Aucun secret détecté n'est stocké ni journalisé : seulement des catégories (`SensitiveContentDetector::TYPE_*`).
  Toute donnée dérivée du texte (mots-clés, futur prompt LLM) est calculée sur `SensitiveContentDetector::redact()`.
  Chaque nouvel extracteur ou describer a un test vérifiant que le secret n'apparaît pas dans `summary`/`keywords`.

## Commandes (depuis la racine ; `GLPI=11` ou `GLPI=12`, 12 par défaut)

| But | Commande |
|---|---|
| Construire les images (officielles + Tesseract) | `make build GLPI=12` / `make build GLPI=11` |
| GLPI d'exécution (12 : http://localhost:8090, 11 : :8091 ; glpi/glpi) | `make up GLPI=…` puis `make plugin-install GLPI=…` |
| Lancer l'action automatique d'analyse | `make cron GLPI=…` |
| Console GLPI | `make console CMD="plugin:list"` |
| Environnement QA (première fois, par version) | `make qa-setup GLPI=…` |
| **Tout vérifier sur les 2 versions (= CI)** | `make check-all` |
| Vérifier une version | `make check GLPI=11` |
| Tests ciblés | `make test FILTER=Ocr` |
| Corriger le style | `make rector-fix GLPI=12 && make rector-fix GLPI=11` |

Docker au lieu de Podman : `COMPOSE_BIN="docker compose"`. Après une modification du schéma SQL ou de `setup.php` :
`make plugin-reinstall` (exécution) et `make qa-setup` (QA).

## Compatibilité GLPI 11 / 12 : pièges connus

- Ne **pas** redéclarer `$rightname` (non typé en 11, `string` en 12) : surcharger `canView()`/`canCreate()`… à la place.
- `Session::haveRight()` renvoie `bool|int` en 11 : normaliser sans cast (Rector v12 retire les casts « redondants »).
- Pas de syntaxe PHP 8.3 (constantes typées `const array`, `#[\Override]`, `json_validate`) : `composer.json` cible 8.2.
- `symfony/process` n'est qu'une dépendance **de dev** du cœur (absente en production) : lancer `tesseract` via
  `proc_open()` natif avec un tableau d'arguments (pas de shell), uniquement dans `src/Ocr/TesseractOcr.php`.
  Toujours valider sur les images **d'exécution** (`make up` + `make cron`), pas seulement en QA.
- Rector et PHPStan utilisent les référentiels de la version GLPI du conteneur : un changement n'est fini que si
  `make check-all` est vert.

## Règles du projet

- **Pas de modification du cœur GLPI.** S'il manque un hook, le noter dans `docs/roadmap.md` (PR amont).
- Les hooks sur `Document` restent **rapides** (mise en file d'attente seulement) ; le travail lourd
  se fait dans l'action automatique `Description::cronAnalyze`.
- Ne pas ajouter dans `composer.json` du plugin une dépendance déjà fournie par le cœur
  (PhpSpreadsheet, thecodingmachine/safe, symfony/process…) : la CI officielle le refuse.
- Utiliser les fonctions `Safe\...` (`use function Safe\preg_match;`) : exigé par PHPStan.
- Accès typé aux `$item->fields` via `GlpiPlugin\Documentaliste\Fields` (PHPStan niveau max).
- Chaînes traduisibles : `__('...', 'documentaliste')`. Sources en anglais.
- En-tête de licence MIT (`plugins/documentaliste/tools/HEADER`) sur chaque fichier PHP/Twig ; `make headers`.
- Migrations idempotentes dans `src/Install.php` (appelé aussi lors des mises à jour) ; incrémenter la version du plugin
  (`setup.php` + `documentaliste.xml`) quand le schéma change.
- **Incrémenter `Analyzer::VERSION`** dès que les résultats stockés changeraient : les anciens ne sont alors plus
  recopiés par le dédoublonnage, et sont ré-analysés par l'action automatique.
- IDs d'options de recherche : plage provisoire 97101+ (`Description::SO_*`), à réserver officiellement avant publication.
- Mettre à jour `plugins/documentaliste/CHANGELOG.md` à chaque changement fonctionnel.

## Méthode de travail attendue

1. Explorer `glpi-src/` **et** `glpi-src-11/` pour confirmer les API, puis proposer un plan court.
2. Écrire le test d'abord quand c'est possible (`tests/Unit` sans BDD, `tests/Functional` avec `DbTestCase`).
3. Terminer chaque tâche par `make check-all` vert ; ne pas annoncer « fini » sinon.
