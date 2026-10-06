# syntax=docker/dockerfile:1.7
# Foodtruck - service interne de lecture des fiches de recettes (Tesseract + Poppler)
# Aucun port publié : joignable seulement par foodtruck-app sur le réseau foodtruck_internal.
FROM debian:bookworm-slim

RUN apt-get update \
 && apt-get install -y --no-install-recommends tesseract-ocr tesseract-ocr-fra tesseract-ocr-osd poppler-utils python3 python3-pil \
 && rm -rf /var/lib/apt/lists/* \
 && useradd --system --no-create-home --shell /usr/sbin/nologin ocr

# Modèle français « best » de Tesseract (plus précis que celui du paquet Debian), vérifié par somme de contrôle
ADD --checksum=sha256:907743d98915c91a3906dfbf6e48b97598346698fe53aaa797e1a064ffcac913 --chmod=644 https://github.com/tesseract-ocr/tessdata_best/raw/main/fra.traineddata /usr/share/tesseract-ocr/5/tessdata/fra.traineddata

# Dictionnaire français (liste « an-array-of-french-words », licence MIT, 336 000 formes) pour recouper les mots collés
ADD --checksum=sha256:75e81515ed0714c8d98d6aa0cc71ba3094834653de005f9bdbb874257639e92d --chmod=644 https://raw.githubusercontent.com/words/an-array-of-french-words/24a6b29562cd0f8c4090262dbe303413f68aafe9/index.json /app/mots-fr.json

WORKDIR /app
COPY layout.py server.py words.py ./

ENV PYTHONUNBUFFERED=1 OMP_THREAD_LIMIT=1 OCR_LANG=fra OCR_DPI=300 OCR_PORT=8080
USER ocr
EXPOSE 8080
HEALTHCHECK --interval=60s --timeout=20s --start-period=20s --retries=3 \
  CMD python3 -c "import urllib.request,sys; sys.exit(0 if b'\"ok\": true' in urllib.request.urlopen('http://127.0.0.1:8080/sante', timeout=15).read() else 1)"
CMD ["python3", "server.py"]
