FROM node:16-alpine

WORKDIR /app

COPY package*.json ./
COPY . .

RUN npm install --legacy-peer-deps

RUN npm run build

FROM nginx:alpine
COPY --from=0 /app/build /usr/share/nginx/html

EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
