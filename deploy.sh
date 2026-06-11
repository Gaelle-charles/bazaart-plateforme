#!/bin/bash
# deploy.sh — Script de déploiement Bazaart → app.bazaart.fr
#
# Usage depuis ta machine locale :
#   ./deploy.sh
#
# Ce script se connecte en SSH au droplet DigitalOcean et exécute
# les opérations de mise à jour en remote (git pull + migrations + cache)

set -e  # Arrêter immédiatement si une commande échoue

# ── Configuration ────────────────────────────────────────────────────────────
SERVER="root@206.189.3.112"
APP_DIR="/root/bazaart-plateforme"   # Dossier du repo cloné sur le serveur

echo "🚀 Déploiement vers app.bazaart.fr"
echo "   Serveur : $SERVER"
echo "   Dossier : $APP_DIR"
echo ""

# ── Étape 1 : Pull du dernier code depuis GitHub ─────────────────────────────
echo "📦 Récupération du code depuis GitHub..."
ssh "$SERVER" "cd $APP_DIR && git pull origin main"

# ── Étape 2 : Rebuild du container PHP si le Dockerfile a changé ─────────────
echo "🐳 Rebuild des containers Docker si nécessaire..."
ssh "$SERVER" "cd $APP_DIR && docker compose -f docker-compose.prod.yml build --no-cache app 2>/dev/null || true"

# ── Étape 3 : Redémarrer les containers ──────────────────────────────────────
echo "🔄 Redémarrage des containers..."
ssh "$SERVER" "cd $APP_DIR && docker compose -f docker-compose.prod.yml up -d"

# ── Étape 4 : Installer/mettre à jour les dépendances Composer ───────────────
echo "📚 Installation des dépendances Composer (prod, sans dev)..."
ssh "$SERVER" "cd $APP_DIR && docker compose -f docker-compose.prod.yml exec -T app composer install --no-dev --optimize-autoloader --no-interaction"

# ── Étape 5 : Appliquer les migrations Doctrine ──────────────────────────────
echo "🗃️  Application des migrations base de données..."
ssh "$SERVER" "cd $APP_DIR && docker compose -f docker-compose.prod.yml exec -T app php bin/console doctrine:migrations:migrate --no-interaction"

# ── Étape 6 : Vider et regénérer le cache Symfony ────────────────────────────
echo "🧹 Vidage du cache Symfony..."
ssh "$SERVER" "cd $APP_DIR && docker compose -f docker-compose.prod.yml exec -T app php bin/console cache:clear --env=prod"

# ── Étape 7 : Corriger les permissions des dossiers var/ et public/uploads/ ──
echo "🔒 Correction des permissions..."
ssh "$SERVER" "cd $APP_DIR && docker compose -f docker-compose.prod.yml exec -T app chmod -R 777 var/ public/uploads/ 2>/dev/null || true"

echo ""
echo "✅ Déploiement terminé ! Le site est à jour sur https://app.bazaart.fr"
