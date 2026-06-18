---
name: project-mac-network-incident
description: Diagnostic en cours (16 juin) — Mac de Gaëlle ne peut plus accéder à app.bazaart.fr depuis son Wi-Fi ; stack réseau incohérent après commandes réseau
metadata:
  type: project
---

## État au moment de la fermeture du terminal

**Symptôme** : app.bazaart.fr inaccessible depuis le Mac de Gaëlle sur son Wi-Fi depuis le 16 juin. Accessible depuis 4G et depuis d'autres ordis.

**Diagnostic complet effectué :**
- DNS : propre (192.168.1.1, pas de DNS64)
- Proxy : aucun
- /etc/hosts : pas d'entrée parasite
- IP résolue correctement (206.189.3.112)
- TCP s'établit (Connected port 443)
- TLS reset après 35s → `Connection reset by peer`
- fail2ban : **pas installé** sur le serveur
- iCloud Private Relay : désactivé
- `curl http://api.ipify.org` échoue aussi (HTTP pur !) → stack réseau Mac incohérent
- Safari et Chrome ne chargent pas app.bazaart.fr non plus

**Commandes réseau lancées pendant le diagnostic (qui ont pu déstabiliser le stack) :**
- `sudo networksetup -setv6off Wi-Fi` (IPv6 désactivé sur Wi-Fi)
- `sudo dscacheutil -flushcache; sudo killall -HUP mDNSResponder`

**Prochaine étape convenue** : redémarrer le Mac complètement → retester app.bazaart.fr

**Why:** Le stack réseau macOS semble dans un état incohérent après les commandes de diagnostic. HTTP et HTTPS échouent pour certaines destinations (bazaart, api.ipify.org) mais pas Google dans le navigateur.

**How to apply:** À la reprise, demander d'abord si elle a redémarré le Mac. Si oui et que ça marche → clore l'incident. Si oui et que ça ne marche toujours pas → relancer `networksetup -setv6automatic Wi-Fi` pour réactiver l'IPv6 proprement, puis retester.
