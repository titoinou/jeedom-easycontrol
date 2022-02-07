PROGRESS_FILE=/tmp/dependancy_easycontrol_in_progress
if [ ! -z $1 ]; then
	PROGRESS_FILE=$1
fi
touch ${PROGRESS_FILE}
echo 0 > ${PROGRESS_FILE}
silent sudo killall bosch-xmpp
echo "Mise à jour APT et installation des packages nécessaires"
sudo apt-get clean
echo 30 > ${PROGRESS_FILE}
sudo apt-get update
echo 60 > ${PROGRESS_FILE}
echo "Nettoyage anciens modules"
sudo npm ls -g --depth 0 2>/dev/null | grep "bosch-xmpp@" >/dev/null 
if [ $? -ne 1 ]; then
  echo "[Suppression bosch-xmpp global"
  silent sudo npm rm -g bosch-xmpp
fi
cd ${BASEDIR};
#remove old local modules
sudo rm -rf node_modules &>/dev/null
sudo rm -f package-lock.json &>/dev/null
echo 75 > ${PROGRESS_FILE}
echo "Installation de Bosch XMPP, veuillez patienter"
silent sudo mkdir node_modules
silent sudo chown -R www-data:www-data .
sudo npm install -g bosch-xmpp
serverversion=`bosch-xmpp -v`;
echo 100 > ${PROGRESS_FILE}
echo "Bosch XMPP version ${serverversion} installé."
rm ${PROGRESS_FILE}
