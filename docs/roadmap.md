# Feuille de route

## ✅ Jalon 1 : socle (fait)

- Table, file d'attente sur les hooks `Document`, nettoyage à la purge
- Action automatique `analyze` (traitement par lots, dédoublonnage par sha1sum, 3 tentatives)
- Extraction locale : texte, docx/pptx/odt/odp, xlsx/xls/ods, métadonnées d'image
- `LocalDescriber` + `SensitiveContentDetector`
- Onglet « Description du document », options de recherche sur Document, hook `documentaliste_document_described`
- Environnement Podman (runtime + QA identique à la CI officielle), `make check` vert

## ✅ Jalon 1 bis : compatibilité, OCR, confidentialité (fait)

- Compatibilité **GLPI 11.0.x et 12.0.x**, `make check-all` vert sur les deux versions
- OCR local des images avec **Tesseract 5** (captures d'écran, photos de documents) : mots-clés et détection de secrets sur le texte reconnu
- Garde-fou `NoNetworkEgressTest` : aucune donnée ne sort du serveur

## Jalon 2 : configuration et rattrapage

- Page de configuration (`Hooks::CONFIG_PAGE`) : describer actif, taille maximale, entités exclues, types exclus
- Langues OCR configurables (`tesseract --list-langs`)
- Commande console `documentaliste:queue-all` pour analyser les documents existants
- Droits dédiés (voir les descriptions et les niveaux de sensibilité)

## Jalon 3 : extraction étendue

- PDF : `pdftotext` (poppler) si présent, sinon une bibliothèque PHP (vérifier qu'elle n'entre pas en conflit avec les dépendances du cœur)
- OCR des PDF scannés : conversion des pages en images (`pdftoppm`), puis Tesseract
- Courriels `.eml` / `.msg`

## Jalon 4 : describer IA **local**

- `LlmDescriber` utilisant un modèle **installé sur le serveur GLPI** (ex. llama.cpp ou Ollama avec un petit modèle quantifié de 1 à 4 Md de paramètres, CPU uniquement)
- Connexion autorisée **uniquement** en boucle locale (`127.0.0.1`, `::1` ou socket Unix), vérifiée par le code ET par `NoNetworkEgressTest` (liste blanche à revoir explicitement)
- Prompt de résumé en JSON structuré (résumé, mots-clés, catégories), validé avant stockage
- Texte expurgé des secrets détectés AVANT l'envoi au modèle (protection en profondeur, même en local)
- Repli automatique sur `LocalDescriber` en cas d'erreur ou si le modèle est absent

## Jalon 5 : notifications

- Hooks `item_get_events` / `item_get_datas` : balises `##document.description##`, `##document.sensitivity##`
- Événement « document sensible ajouté » sur Ticket

## Jalon 6 : blocage

- `pre_item_add` / `pre_item_update` sur `ITILFollowup`, `Ticket`, `TicketTask` : refus et conseil si un secret est détecté
- Pièces jointes : contrôle synchrone limité (texte léger) dans `pre_item_add` sur `Document`
- Configuration par entité : avertir seulement ou bloquer

## Points ouverts

- Réserver officiellement une plage d'ID d'options de recherche
- Licence : MIT (modèle officiel) ou GPLv3 comme le cœur ?
- Quels hooks manquent dans le cœur ? (à documenter au fil de l'eau, en vue d'une PR amont)
