@ECHO OFF
docker build . -t omeka_s_alamire
docker tag omeka_s_alamire registry.docker.libis.be/omeka_s_alamire
docker push registry.docker.libis.be/omeka_s_alamire
ECHO Image built, tagged and pushed successfully
PAUSE
