FROM nginx:1.25-alpine

# Copiar el template de configuración de Nginx (aprovecha envsubst interno)
COPY docker/nginx-ha.conf.template /etc/nginx/templates/default.conf.template

# Copiar activos estáticos al contenedor (Inmutabilidad)
COPY public/ /var/www/html/public

# Establecer variable default para envsubst
ENV NGINX_ENVSUBST_FILTER="AUDIT_"
