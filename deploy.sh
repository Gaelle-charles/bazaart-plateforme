#!/bin/bash
echo "📦 Envoi des fichiers..."
rsync -avz --progress \
  --exclude='app/var/cache/' \
  --exclude='app/var/log/' \
  --exclude='app/.env.local' \
  --exclude='.DS_Store' \
  /Users/belamour/bazaart/ \
  root@206.189.3.112:/root/bazaart2/

echo "🚀 Vidage du cache..."
ssh root@206.189.3.112 "cd /root/bazaart2 && docker compose -f docker-compose.prod.yml exec -T app php bin/console cache:clear"

echo "✅ Déployé !"
