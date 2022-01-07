#!/bin/bash

CURPATH=$(cd "$(dirname "$0")"; pwd)

mkdir -p ${CURPATH}/../var
chown -R php:www ${CURPATH}/../var


