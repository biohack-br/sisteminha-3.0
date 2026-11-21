#!/bin/bash

# Forçar encoding UTF-8
export LANG=en_US.UTF-8
export LC_ALL=en_US.UTF-8	

# Número fixo
NUMERO_FIXO="+55190000000,+55190000000,+55190000000"

# Mensagem do argumento
MENSAGEM="$*"

# Envia SMS
termux-sms-send -n "$NUMERO_FIXO" "$MENSAGEM"
