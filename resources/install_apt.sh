PROGRESS_FILE=/tmp/jeedom/easycontrol/dependance
touch ${PROGRESS_FILE}
echo 0 > ${PROGRESS_FILE}
echo "Installation dépendances EasyControl"
sudo apt-get clean
echo 30 > ${PROGRESS_FILE}
sudo apt-get update
echo 50 > ${PROGRESS_FILE}
echo "Suppressions anciennes versions de Bosch XMPP"
sudo npm rm -g bosch-xmpp
echo 60 > ${PROGRESS_FILE}
echo 75 > ${PROGRESS_FILE}
echo "Installation dernière version de Bosch XMPP"
sudo npm install -g bosch-xmpp
serverversion=`bosch-xmpp -v`;
echo 100 > ${PROGRESS_FILE}
echo "Bosch XMPP version ${serverversion} installé."
rm ${PROGRESS_FILE}
