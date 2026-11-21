#!/bin/bash

valor_gasolina=$(./gasolina.sh)
valor_petroleo=$(./petroleo.sh)
valor_dolar=$(./dolar.sh)
valor_moeda=$(./prata.sh)
valor_ouro=$(./ouro.sh)
valor_bitcoin=$(./bitcoin.sh)
tempo_graus=$(./clima.sh)

echo "Indaiatuba: ${tempo_graus}ºC "
echo "Gasolina: ${valor_gasolina}"
echo "Petróleo: ${valor_petroleo}"
echo "Dólar: ${valor_dolar}"
echo "Prata: ${valor_moeda}"
echo "Ouro: ${valor_ouro}"
echo "Bitcoin: ${valor_bitcoin}"

python ~/commoditties3.py
