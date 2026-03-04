# Front – Base commune et thème

## Structure

- **`base_front.html.twig`** : base de toutes les pages front. Elle inclut :
  - Meta, titre, Font Awesome, importmap (app.js + app.css)
  - Script anti-flash pour appliquer le thème (dark/light) au chargement
  - **Navbar partagée** (`_navbar.html.twig`)
  - Bloc `content` pour le contenu principal
  - Bloc `footer` optionnel

- **`_navbar.html.twig`** : navbar commune (HTML uniquement, pas de `<style>`).

- **CSS centralisé** : `assets/styles/app.css`
  - Variables light/dark (`:root` et `.dark-theme`)
  - Styles globaux (body, .container, .btn)
  - Styles de la navbar et du footer

- **JS centralisé** : `assets/app.js` (chargé via importmap)
  - Bascule thème (localStorage clé **`theme`**, valeurs `light` | `dark`)
  - Menu mobile
  - Lien actif dans la navbar

## Utilisation pour un nouveau module

1. **Étendre la base** :
   ```twig
   {% extends 'front/base_front.html.twig' %}
   ```

2. **Définir les blocs** :
   - `{% block title %}Mon titre{% endblock %}`
   - `{% block current_page %}home{% endblock %}` (pour la surbrillance nav : home, articles, tutors, calendar, messenger)
   - `{% block content %}...{% endblock %}`
   - Optionnel : `{% block footer %}...{% endblock %}`
   - Optionnel : `{% block stylesheets %}<style>...</style>{% endblock %}` pour du CSS **spécifique à la page** (sans redéfinir :root ni navbar).

3. **Ne plus** :
   - Inclure la navbar à la main
   - Dupliquer les variables CSS ou les styles de la navbar
   - Dupliquer le script du thème (tout est dans app.js)
   - Utiliser une autre clé localStorage que `theme` pour le thème

## Migration d’une page existante

- Remplacer le DOCTYPE/html/head/body par `extends` + blocs.
- Supprimer tout le bloc `<style>` qui contient `:root`, `.dark-theme`, navbar, boutons.
- Garder uniquement les styles **spécifiques** à la page dans `{% block stylesheets %}`.
- Supprimer l’`include` de la navbar et tout script de thème / menu mobile.

## État des templates front

- **Migrés** (étendent `base_front`, navbar partagée, thème centralisé) : `index`, `auth/login`, `auth/register`, `article/articles`, `messenger/messages`, `reservation/tutor`, `reservation/calendar`.
