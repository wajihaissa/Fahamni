# -*- coding: utf-8 -*-
path = 'templates/back/messenger/alerts.html.twig'
with open(path, 'r', encoding='utf-8') as f:
    s = f.read()
new = 'alert("Erreur réseau.");'
# Try common apostrophe variants
for old in ["alert('Erreur lors de l'envoi.');", "alert('Erreur lors de l\u2019envoi.');"]:
    if old in s:
        s = s.replace(old, new)
        break
with open(path, 'w', encoding='utf-8') as f:
    f.write(s)
print("Done")
