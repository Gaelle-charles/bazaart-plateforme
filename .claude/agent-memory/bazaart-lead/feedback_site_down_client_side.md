---
name: site-down-client-side
description: "Site qui semble down côté Gaëlle = vérifier d'ABORD son réseau/Mac (NAT64/IPv6) avant de toucher au serveur"
metadata:
  type: feedback
---

Quand Gaëlle signale que bazaart.fr / app.bazaart.fr est **inaccessible**, ne pas conclure trop vite à une panne serveur : commencer par **prouver si le site répond pour les autres** avant toute intervention sur le droplet.

**Why:** Incident du 16 juin 2026. Symptômes très « serveur en panne » : navigateur en `chrome-error`, `curl` qui timeout/`Connection reset by peer` pendant le handshake TLS, **SSH port 22 en timeout**. En réalité le serveur était parfaitement sain (nginx Up, certif valide jusqu'au 8 sept, logs nginx montrant des `200` à des vrais visiteurs en temps réel). La cause : **le Mac de Gaëlle était en IPv6-only / NAT64** (adresse `64:ff9b::` synthétisée par DNS64) et faisait passer même l'IPv4 par un chemin NAT64 cassé → TLS resetté et SSH bloqué pour elle seule. Un autre ordi sur le même Wi-Fi et le partage 4G fonctionnaient.

**How to apply:** Indice n°1 = une adresse `64:ff9b::...` dans la résolution (`dig`, `curl -v`) → suspecter NAT64/IPv6 côté client, PAS le DNS ni le serveur. Séquence de tri rapide :
1. Le site répond-il pour d'autres ? (téléphone en 4G, autre machine, logs nginx `docker logs bazaart_nginx`). Si oui → problème CLIENT, ne pas toucher au serveur.
2. Test serveur local depuis le droplet : `curl -skiv https://127.0.0.1/ -H 'Host: app.bazaart.fr'` + `nginx -t` + expiry certif. Si OK → confirme serveur sain.
3. Côté Mac : `networksetup -setv6off Wi-Fi` + flush DNS ; vérifier iCloud Private Relay (cause fréquente), VPN, DNS perso, `/etc/hosts`.

Le « j'ai l'impression que mes DNS ont un problème » de Gaëlle venait du fait que `64:ff9b::` est un artefact DNS64 — donc l'intuition DNS n'était pas absurde, mais la cause était le réseau local du Mac. Voir [[project_plateforme_infra]].
