@echo off
setlocal
cd /d "%~dp0"

if not defined JMETER_HOME (
  echo Set JMETER_HOME to your JMeter folder, for example:
  echo   set JMETER_HOME=C:\apache-jmeter-5.6.3
  exit /b 1
)

if not defined USERS set USERS=50
if not defined RAMPUP set RAMPUP=60
if not defined LOOPS set LOOPS=40
if not defined HOST set HOST=localhost
if not defined PORT set PORT=80

if not exist users.csv (
  echo Generating users.csv ...
  C:\xampp\php\php.exe "%~dp0generate_users_csv.php"
)

if not exist results mkdir results

set HEAP=-Xms512m -Xmx2048m
set CSV=%~dp0users.csv

"%JMETER_HOME%\bin\jmeter.bat" -n -t "%~dp0epassport-student-login.jmx" -l "%~dp0results\run.jtl" -j "%~dp0results\jmeter.log" -e -o "%~dp0results\html" -Jcsvfile="%CSV%" -Jusers=%USERS% -Jrampup=%RAMPUP% -Jloops=%LOOPS% -Jhost=%HOST% -Jport=%PORT%

echo.
echo Open results\html\index.html in a browser.
endlocal
