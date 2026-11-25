# Documentaliste

Plugin GLPI 11 et 12 qui décrit automatiquement les pièces jointes (images, Word, Excel, PowerPoint, OpenDocument,
texte, avec OCR local des images) et stocke une **description synthétique, des mots-clés et les catégories de contenu sensible détectées**
pour la recherche, les notifications et d'autres plugins.

**Aucune donnée ne quitte le serveur** : tous les traitements sont locaux (PHP et Tesseract), ce qui est garanti par un test automatique.

Prérequis sur un serveur de production : `apt install tesseract-ocr tesseract-ocr-fra tesseract-ocr-eng` (optionnel : sans lui, les images ne sont pas lues).

## Démarrage rapide

Prérequis : Podman + podman-compose (ou Docker : ajouter `COMPOSE_BIN="docker compose"` aux commandes `make`).

```bash
git clone --depth 1 --branch 12.0.2  https://github.com/glpi-project/glpi.git glpi-src     # références en lecture seule
git clone --depth 1 --branch 11.0.13 https://github.com/glpi-project/glpi.git glpi-src-11
make build GLPI=12 && make build GLPI=11       # images officielles + Tesseract
make up && make plugin-install                 # GLPI 12 sur http://localhost:8090 (glpi / glpi)
make up GLPI=11 && make plugin-install GLPI=11 # GLPI 11 sur http://localhost:8091
make cron                                      # lance l'analyse des documents en attente

make qa-setup GLPI=12 && make qa-setup GLPI=11 # une seule fois : environnements de test identiques à la CI officielle
make check-all                                 # lint, style, rector, en-têtes, phpstan max, phpunit, sur les 2 versions
```

`make help` liste toutes les commandes.

## Documentation

- [docs/architecture.md](docs/architecture.md) : flux, composants, table, API pour les autres plugins
- [docs/roadmap.md](docs/roadmap.md) : jalons suivants (configuration, PDF/OCR, IA, notifications, blocage)
- [CLAUDE.md](CLAUDE.md) : conventions du projet (également lues par Claude Code)
