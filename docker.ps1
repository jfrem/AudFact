# ────────────────────────────────
# Script de limpieza total Docker
# ────────────────────────────────

Write-Host "Deteniendo todos los contenedores..."
$containers = docker ps -q
if ($containers) { docker stop $containers } else { Write-Host "No hay contenedores activos." }

Write-Host "Eliminando todos los contenedores..."
$allContainers = docker ps -aq
if ($allContainers) { docker rm $allContainers } else { Write-Host "No hay contenedores para eliminar." }

Write-Host "Eliminando todas las imágenes..."
$images = docker images -q
if ($images) { $images | ForEach-Object { docker rmi -f $_ } } else { Write-Host "No hay imágenes para eliminar." }

Write-Host "Eliminando todos los volúmenes..."
$volumes = docker volume ls -q
if ($volumes) { $volumes | ForEach-Object { docker volume rm $_ } } else { Write-Host "No hay volúmenes para eliminar." }

Write-Host "Limpiando redes no usadas..."
docker network prune -f

Write-Host "Limpieza completa. Estado actual de Docker:"
docker ps -a
docker images
docker volume ls
docker network ls