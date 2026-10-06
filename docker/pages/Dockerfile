# syntax=docker/dockerfile:1.7
# Foodtruck - service interne « pages » (v0.18.0) : PDF ou photo d'une fiche → images pour le modèle de vision.
# Aucune reconnaissance de texte (c'est le modèle de srv-nas qui lit). Aucun port publié : réseau foodtruck_internal.
FROM debian:bookworm-slim

RUN apt-get update \
 && apt-get install -y --no-install-recommends poppler-utils python3 python3-pil \
 && rm -rf /var/lib/apt/lists/* \
 && useradd --system --no-create-home --shell /usr/sbin/nologin pages

WORKDIR /app
COPY server.py ./
RUN chmod 755 /app && chmod 644 /app/server.py

ENV PYTHONUNBUFFERED=1 PAGES_PORT=8080
USER pages

# Vérifié à la construction, avec l'utilisateur du service : le programme se charge et Poppler répond.
RUN python3 -c "import server; print('foodtruck-pages prêt :', server.health())"
EXPOSE 8080
HEALTHCHECK --interval=60s --timeout=20s --start-period=10s --retries=3 \
  CMD python3 -c "import urllib.request,sys; sys.exit(0 if b'\"ok\": true' in urllib.request.urlopen('http://127.0.0.1:8080/sante', timeout=15).read() else 1)"
CMD ["python3", "server.py"]
