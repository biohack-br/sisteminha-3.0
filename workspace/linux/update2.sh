#!/bin/bash

echo "Iniciando..."
echo ""
echo "Commodities..."
python ~/commoditties2.py
echo "cotações..."
~/update.sh
echo "Consolidando os dados..."
~/consolidado.sh
echo "subindo via FTP..."
~/ftp.sh
echo "Concluido..."
