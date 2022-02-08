PROGRESS_FILE=/tmp/jeedom/easycontrol/dependance
ACTUALVERSION=$(sudo bosch-xmpp -v)
VERSION=$(sudo npm view -g bosch-xmpp version)
if [ $ACTUALVERSION = $VERSION ]; then
  UPDATE=0
else
  UPDATE=1
fi
touch ${PROGRESS_FILE}
echo 0 > ${PROGRESS_FILE}
echo "Installation dépendances EasyControl"
sudo apt-get clean
echo 30 > ${PROGRESS_FILE}
sudo apt-get update
echo 50 > ${PROGRESS_FILE}
if [ $UPDATE = 1 ]; then
  echo "Suppressions anciennes versions de Bosch XMPP"
  sudo npm remove -g bosch-xmpp
fi
echo 75 > ${PROGRESS_FILE}
if [ $UPDATE = 1 ]; then
  echo "Installation dernière version de Bosch XMPP"
  sudo npm install -g bosch-xmpp
fi
echo 100 > ${PROGRESS_FILE}
if [ $UPDATE = 1 ]; then
  echo "Bosch XMPP version ${VERSION} installé."
else
  echo "Bosch XMPP version ${VERSION} est déjà installé."
fi
rm ${PROGRESS_FILE}
