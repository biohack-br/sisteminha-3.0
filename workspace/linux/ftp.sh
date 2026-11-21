#!/bin/bash

lftp -u 'if0_43335911','9dsGc20JUNve' ftpupload.net -p 21 -e "set ssl:verify-certificate no; cd htdocs; put cotacoes.json; put quotes_cache.json; put consolidado.json;  bye"
