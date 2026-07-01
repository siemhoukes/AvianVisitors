#!/usr/bin/env bash

if [ "$EUID" == 0 ]
  then echo "Please run as a non-root user."
  exit
fi

if [ "$(uname -m)" != "aarch64" ] && [ "$(uname -m)" != "x86_64" ];then
  echo "BirdNET-Pi requires a 64-bit OS.
It looks like your operating system is using $(uname -m),
but would need to be aarch64."
  exit 1
fi

PY_VERSION=$(python3 -c "import sys; print(f'{sys.version_info[0]}{sys.version_info[1]}')")
if [ "${PY_VERSION}" == "39" ] ;then
  echo "### BirdNET-Pi requires a newer OS. Bullseye is deprecated, please use Bookworm. ###"
  [ -z "${FORCE_BULLSEYE}" ] && exit
fi

# we require passwordless sudo
sudo -K
if ! sudo -n true; then
    echo "Passwordless sudo is not working. Aborting"
    exit
fi

# Simple new installer
HOME=$HOME
USER=$USER

export HOME=$HOME
export USER=$USER

PACKAGES_MISSING=
for cmd in git jq ; do
  if ! which $cmd &> /dev/null;then
      PACKAGES_MISSING="${PACKAGES_MISSING} $cmd"
  fi
done
if [[ ! -z $PACKAGES_MISSING ]] ; then
  sudo apt update
  sudo apt -y install $PACKAGES_MISSING
fi

# Low-memory Pis (3B+ / Zero 2W, <=1GB RAM): expand swap and disable WiFi power-save
# per https://github.com/mcguirepr89/BirdNET-Pi/wiki/RPi0W2-Installation-Guide
TOTAL_MEM_KB=$(awk '/MemTotal/ {print $2}' /proc/meminfo)
if [ "${TOTAL_MEM_KB}" -lt 2000000 ]; then
  if [ -f /etc/dphys-swapfile ]; then
    echo "Low-memory Pi detected ($((TOTAL_MEM_KB / 1024)) MB RAM): expanding swap to 1024 MB"
    sudo sed -i 's/^CONF_SWAPSIZE=.*/CONF_SWAPSIZE=1024/' /etc/dphys-swapfile
    sudo sed -i 's/^#\?CONF_MAXSWAP=.*/CONF_MAXSWAP=1024/' /etc/dphys-swapfile
    sudo systemctl restart dphys-swapfile
  elif [ ! -f /swapfile ] && ! grep -q '^/swapfile' /etc/fstab; then
    # Debian Trixie has no dphys-swapfile (zram only, and zram alone is thin
    # for TensorFlow on 1 GB) - add a persistent 2 GB swapfile instead.
    echo "Low-memory Pi detected ($((TOTAL_MEM_KB / 1024)) MB RAM): adding a 2 GB /swapfile (no dphys-swapfile on this OS)"
    if sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile \
       && sudo mkswap /swapfile && sudo swapon /swapfile; then
      echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab > /dev/null
    else
      echo "WARNING: swapfile setup failed; continuing without extra swap"
      sudo rm -f /swapfile
    fi
  fi
  echo "Disabling WiFi power-save"
  sudo iw wlan0 set power_save off 2>/dev/null || true
  printf '[connection]\nwifi.powersave = 2\n' | sudo tee /etc/NetworkManager/conf.d/wifi-powersave.conf > /dev/null
fi

branch=avian-visitors
git clone -b $branch --depth=1 https://github.com/siemhoukes/AvianVisitors.git ${HOME}/BirdNET-Pi &&

$HOME/BirdNET-Pi/scripts/install_birdnet.sh
if [ ${PIPESTATUS[0]} -eq 0 ];then
  echo "Installation completed successfully"
  sudo reboot
else
  echo "The installation exited unsuccessfully."
  exit 1
fi
