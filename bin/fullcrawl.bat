@ECHO OFF
setlocal DISABLEDELAYEDEXPANSION

SET BIN_TARGET=%~dp0/fullcrawl

php "%BIN_TARGET%" %*
