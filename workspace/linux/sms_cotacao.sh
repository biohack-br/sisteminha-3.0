#!/bin/bash

# Forçar encoding UTF-8
export LANG=en_US.UTF-8
export LC_ALL=en_US.UTF-8

# Executa a cotaço
./update.sh

valor_gasolina=$(./gasolina.sh)
valor_petroleo=$(./petroleo.sh)
valor_dolar=$(./dolar.sh)
valor_moeda=$(./prata.sh)
valor_ouro=$(./ouro.sh)
valor_bitcoin=$(./bitcoin.sh)
tempo_graus=$(./clima.sh)
valor_commoditties=$(python ./commoditties.py)

MENSAGEM="
SPARTA-SYS: Data: $(date '+%d-%m-%Y')
Hora: $(date '+%H:%M:%S')
=========================

Indaiatuba: ${tempo_graus}ºC
Gasolina: ${valor_gasolina}
Petróleo: ${valor_petroleo}
Dólar: ${valor_dolar}
Prata: ${valor_moeda}
Ouro: ${valor_ouro}
Bitcoin: ${valor_bitcoin}"

# Envia via SMS
./sms.sh "$MENSAGEM"

# Envia via SMS
python commoditties.py

~/sms.sh "Fim do boletim de Noticias!====================== $(date '+%d-%m-%Y %H:%M')"

