FROM node:22-alpine AS build

WORKDIR /app
COPY website/package*.json ./
RUN npm ci

# Copiar el portal
COPY website/ ./
# Copiar la documentación Markdown desde el root al lugar correcto del portal
COPY plans/ ./docs/
COPY README.md BUSINESS.md DESIGN.md ./docs/

RUN npm run build

# Stage 2: Serve con Nginx
FROM nginx:1.25-alpine
RUN rm /etc/nginx/conf.d/default.conf
COPY docker/docs-nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/build /usr/share/nginx/html
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]