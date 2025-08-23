
#!/bin/bash

echo "🚀 Starting Rose Academy Deployment..."

# Build and start containers
echo "📦 Building Docker containers..."
docker-compose build --no-cache

echo "🔧 Starting services..."
docker-compose up -d

echo "⏳ Waiting for services to be ready..."
sleep 30

echo "🗄️ Running database migrations..."
docker-compose exec app php artisan migrate --force

echo "🔗 Creating storage link..."
docker-compose exec app php artisan storage:link

echo "📊 Seeding database..."
docker-compose exec app php artisan db:seed --force

echo "✅ Deployment completed successfully!"
echo "🌐 Your application is now running at: http://localhost"
echo "📊 Database: MySQL on port 3306"
echo "🔴 Redis: Available on port 6379"

echo "📋 To check logs, run: docker-compose logs -f"
echo "🛑 To stop services, run: docker-compose down"
