#!/usr/bin/php
<?php


//Allg. Einstellungen

$device = '/dev/hidraw0';

///dev/hidraw0';
// $moxa_ip = "192.168.123.115";  //ETH-RS232 converter in TCP_Server mode
// $moxa_port = 20108;
$moxa_timeout = 10;
$warte_bis_naechster_durchlauf = 4; //Zeit zw. zwei Abfragen in Sekunden
$tmp_dir = "/tmp/inv1/";             //Speicherort/-ordner fuer akt. Werte -> am Ende ein / !!!
if (!file_exists($tmp_dir)) {
	mkdir("/tmp/inv1/", 0777);
	}
$error = [];
$schleifenzaehler = 0;

//Logging/Debugging Einstellungen:
$debug = true;         //Debugausgaben und Debuglogfile
$debug2 = true;         //erweiterte Debugausgaben nur auf CLI
$log2console = 1;
$fp_log = 1;
$script_name = "infinipoll10k.php";
$logfilename = "/home/ahmed/infinipoll_10k_";     //Debugging Logfile

//Initialisieren der Variablen:
$is_error_write = false;
$totalcounter = 0;
$daybase = 0;
$daybase_yday = 0;

//Syslog oeffnen
openlog($script_name, LOG_PID | LOG_PERROR, LOG_LOCAL0);
syslog(LOG_ALERT,"INFINIPOLL 10K Neustart");

if($debug) $fp_log = @fopen($logfilename.date("Y-M-d").".log", "a");
if($debug) logging("INFINIPOLL 10K Neustart");


// Get model,version and protocolID for infini_startup.php
// Modell  abfragen
$fp = fopen($device, 'rwb+');
// $fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout); // Open connection to the inverter
// ProtokollID abfragen
$protid = send_and_get_response($fp, "^P003PI"); //QPI Protocol ID abfragen 6byte
if($debug2) echo "ProtocolID: ".$protid."\n";
// Serial abfragen
$serial = send_and_get_response($fp, "^P003ID"); //QPI Protocol ID abfragen 6byte
if($debug2) echo "Serial: ".$serial."\n";
// CPU Version abfragen
$version = send_and_get_response($fp, "^P004VFW");
if($debug2) echo "CPU Version: ".$version."\n";
// CPU secondary Version abfragen
$version2 = send_and_get_response($fp, "^P005VFW2");
if($debug2) echo "CPU secondary Version: ".$version2."\n";
// Modell  abfragen
$byte=send_and_get_response($fp, "^P003MD");
$array = explode(",", $byte);
$modelcode = $array[0];
if($modelcode="000") $model="MPI Hybrid 10KW/3P";
$modelva = $array[1];
$modelpf = $array[2];
$modelbattpcs = $array[3];
$modelbattv = $array[4];
if($debug2)
{
	echo "Modell: ".$model."\n";
	echo "VA: ".$modelva."\n";
	echo "PowerFactor: ".$modelpf."\n";
	echo "BattPCs: ".$modelbattpcs."\n";
	echo "BattV: ".$modelbattv."\n";
}
// Infos collected, write it to info File
$CMD_INFO = "echo \"$model\nSerial:$serial\nSW:$version\nProtokoll:$protid\n$version\n$version2\"";
if($debug2) echo $CMD_INFO;
write2file_string($tmp_dir."INFO.txt",$CMD_INFO);

//get date+time and set current time from server
//  P002T<cr>: Query current time
$zeit = send_and_get_response($fp, "^P002T");
if($debug) echo "\nAktuelle Zeit im WR: ".$zeit."\n";
if($debug) logging("aktuelle Zeit im WR: ".$zeit);

//Get a starting value for PV_GES:
$daybase = file_get_contents($tmp_dir."PV_GES_yday.txt");
if($daybase==0)
{
    if($debug) logging("Tageszähler war 0 - wird neu vom WR geholt");
    //Get total-counter
	// ^P003ET<cr>: Query total generated energy
	$totalcounter = send_and_get_response($fp, "^P003ET");
	if($debug) echo "KwH_Total: ".$totalcounter." kWh\n";
	// ^P014EDyyyymmddnnn<cr>: Query generated energy of day
	$month=date("m");
	$year=date("Y");
	$day=date("d");
	$check = cal_crc_half("^P014ED".$year.$month.$day);
	$daypower = send_and_get_response($fp, "^P014ED".$year.$month.$day.$check);
	if($debug) echo "WH_Today: ".$daypower." Wh\n";
	$daytemp = $daypower/1000 - ((int)($daypower/1000));
	$pv_ges = ($totalcounter*1000)+($daytemp*1000); // in KWh
	if($debug) echo "PV_GES: ".$pv_ges." Wh\n";
}
fclose($fp);

// MAIN LOOP
while(true)
{
	$err = false;
	$schleifenzaehler++;
	if($schleifenzaehler==100) // Abfrage ca.alle 5 Minuten
	{
		getalarms();
		$schleifenzaehler=0;
		continue;
	}
	if($debug && $fp_log) @fclose($fp_log);
	if($debug) $fp_log = @fopen($logfilename.date("Y-M-d").".log", "a");    //schreibe ein File pro Tag! -> Rotation!
	$err = false;

	//Aufbau der Verbindung zum Serial2ETH Wandler
	$fp = @fopen($device, 'rwb+');
// 	$fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout);
	if (!$fp)
	{
		logging("Fehler beim Verbindungsaufbau: $errstr ($errno)");
		sleep($warte_bis_naechster_durchlauf);
		continue;
	}
	// Wenn genau 23 Uhr Abends, Totalcounter wird als daybase_yesterday gespeichert
	if(date("H")=="23")
	{
		if($debug) logging("23 Uhr Abends, Tageszähler wird gespeichert");
		$daybase_yday = file_get_contents($tmp_dir."PV_GES.txt");
		if($debug) logging("DAYBASE_YESTERDAY: ".$daybase_yday." KWh");
		write2file($tmp_dir."PV_GES_yday.txt",$daybase_yday);
	}
	// Wenn genau 1 Uhr Morgens, Totalcounter wird von PV_GES_yday als TagesBasis geholt:
	if(date("H")=="01")
	{
		if($debug) logging("Ein Uhr Morgens, Tageszähler wird neu gesetzt");
		$daybase = file_get_contents($tmp_dir."PV_GES_yday.txt");
		if($debug) logging("DAYBASE-Counter: ".$daybase." KWh");
	}
	//Abfrage des InverterModus
	// ^P004MOD<cr>: Query working mode
	$modus = send_and_get_response($fp, "^P004MOD");
	echo $modus;
	switch ($modus) 
	{
		case "00":
		$modusT="PowerOn";
		break;
		case "01":
		$modusT="StandBy";
		break;
		case "02":
		$modusT="Bypass";
		break;
		case "03":
		$modusT="Battery";
		break;
		case "04":
		$modusT="Fault";
		break;
		case "05":
		$modusT="Hybrid (Line mode, Grid mode)";
		break;
		case "06":
		$modusT="Charge";
		break;
		default:
		$modusT="unknown";
		break;
	}
	if($modus=="00" || $modus=="01" || $modus=="02")
	{
		if($debug) logging("=====>>>>WR ist im ".$modusT."-Modus, daher sind Abfragen verboten!");
		batterie_nacht();
		getalarms();
		sleep(60); //Warte 1 Minute, weil Nachts eh nicht viel passiert
		continue;
	}
	if($modus=="04") // WR im im Fehlermodus
	{
		fclose($fp);
		getalarms();
		if($debug) logging("WR im FAULT-STATUS!!! Fehler siehe ALARM.txt!");
		sleep(60); //Warte 1 Minuten weil Nachts eh nix passiert
		continue;
	}
	if($debug) logging("================================================");
	if($debug) logging("Modus: ".$modusT);

        //HAUPTABFRAGE send Request for Records, see "Infini-Solar 10KW protocol 20150702.xlsx"
	// ^P003GS<cr>: Query general status
	$byte = send_and_get_response($fp, "^P003GS");
	echo $byte;
	$array = array_map("floatval", explode(',',$byte));
	$pv1volt = ($array[0])/10;
	$pv2volt = ($array[1])/10;
	$pv1amps = ($array[2])/100;
	$pv2amps = ($array[3])/100;
	$battvolt = ($array[4])/10;
	$battcap = ($array[5]);
	$battamps = ($array[7])/10;
	$gridvolt1 = ($array[7])/10;
	$gridvolt2 = ($array[8])/10;
	$gridvolt3 = ($array[9])/10;
	$gridfreq = ($array[10])/100;
	$gridamps1 = ($array[11])/10;
	$gridamps2 = ($array[12])/10;
	$gridamps3 = ($array[13])/10;
	$outvolt1 = ($array[14])/10;
	$outvolt2 = ($array[15])/10;
	$outvolt3 = ($array[16])/10;
	$outfreq = ($array[17])/100;
	//$outamps1 = (substr($byte,96,4)/10);
	//$outamps2 = (substr($byte,101,4)/10);
	//$outamps3 = (substr($byte,106,4)/10);
	$intemp = ($array[21]);
	$maxtemp = ($array[22]);
	$batttemp = ($array[13]);
	if($debug2)
	{
		echo "SolarInput1: ".$pv1volt."V\n";
		echo "SolarInput2: ".$pv2volt."V\n";
		echo "SolarInput1: ".$pv1amps."A\n";
		echo "SolarInput2: ".$pv2amps."A\n";
		echo "BattVoltage: ".$battvolt."V\n";
		echo "BattCap: ".$battcap."%\n";
		echo "BattAmp: ".$battamps."A\n";
		echo "GridVolt1: ".$gridvolt1."V\n";
		echo "GridVolt2: ".$gridvolt2."V\n";
		echo "GridVolt3: ".$gridvolt3."V\n";
		echo "GridFreq: ".$gridfreq."Hz\n";
		echo "GridAmps1: ".$gridamps1."A\n";
		echo "GridAmps2: ".$gridamps2."A\n";
		echo "GridAmps3: ".$gridamps3."A\n";
		echo "OutVolt1: ".$outvolt1."V\n";
		echo "OutVolt2: ".$outvolt2."V\n";
		echo "OutVolt3: ".$outvolt3."V\n";
		echo "OutFreq: ".$outfreq."Hz\n";
		//echo "OutAmps1: ".$outamps1."A\n";
		//echo "OutAmps2: ".$outamps2."A\n";
		//echo "OutAmps3: ".$outamps3."A\n";
		echo "InnerTemp: ".$intemp."°\n";
		echo "CompMaxTemp: ".$maxtemp."°\n";
		echo "BattTemp: ".$batttemp."°\n";
	}
	// ^P003PS<cr>: Query power status
	$byte=send_and_get_response($fp, "^P003PS");
	$array = array_map("floatval", explode(',', $byte));
	echo $array;
	$pv1power = $array[0];
	$pv2power = $array[1];
	// these elements are absent
	$gridpower1 = $array[7];
	$gridpower2 = $array[8];
	$gridpower3 = $array[9];
	$gridpower = $array[10];
	$apppower1 = $array[11];
	$apppower2 = $array[12];
	$apppower3 = $array[13];
	$apppower = $array[14];
	$powerperc = $array[15];
	$acoutact = $array[16];
	if($acoutact== 0) $acoutactT="disconnected";
	if($acoutact== 1) $acoutactT="connected";
	$pvinput1status = $array[13];//substr($byte,71,1);
	$pvinput2status = $array[14];//substr($byte,73,1);
	$battcode_code = $array[15];//substr($byte,75,1);
  	if($battcode_code==0) $battstat="donothing";
	if($battcode_code==1) $battstat="charge";
	if($battcode_code==2) $battstat="discharge";
	$dcaccode_code = substr($byte,77,1);
	if($dcaccode_code==0) $dcaccode="donothing";
	if($dcaccode_code==1) $dcaccode="AC-DC";
	if($dcaccode_code==2) $dcaccode="DC-AC";
	$powerdir_code = substr($byte,79,1);
	if($powerdir_code==0) $powerdir="donothing";
	if($powerdir_code==1) $powerdir="input";
	if($powerdir_code==2) $powerdir="output";

	// ^P014EDyyyymmddnnn<cr>: Query generated energy of day
	$month=date("m");
	$year=date("Y");
	$day=date("d");
	$check = cal_crc_half("^P014ED".$year.$month.$day);
	$daypower = floatval(send_and_get_response($fp, "^P014ED".$year.$month.$day.$check));

	if($debug2)
	{
		echo "PV1_Power: ".$pv1power."W\n";
		echo "PV2_Power: ".$pv2power."W\n";
		echo "GridPower1: ".$gridpower1."W\n";
		echo "GridPower2: ".$gridpower2."W\n";
		echo "GridPower3: ".$gridpower3."W\n";
		echo "GridPower: ".$gridpower."W\n";
		echo "ApperentPower1: ".$apppower1."W\n";
		echo "ApperentPower2: ".$apppower2."W\n";
		echo "ApperentPower3: ".$apppower3."W\n";
		echo "ApperentPower: ".$apppower."W\n";
		echo "AC-Out: ".$acoutactT."\n";
		echo "PowerOutputPercentage: ".$powerperc."%\n";
		echo "BatteryStatus: ".$battstat."\n";
		echo "DC-AC Power direction: ".$dcaccode."\n";
		echo "LinePowerDirection: ".$powerdir."\n";
		echo "Power Today: ".$daypower."\n";
	}
	if($err) //If any error appeared, flush data and collect again
	{
		fclose($fp);
		sleep($warte_bis_naechster_durchlauf);
		continue;
	}

	//Add collected values to correct variables:
	$pv_ges = ($daybase+($daypower/1000)); // in KWh
	if($debug) logging("DEBUG: PV_GES: ".$pv_ges);
//	$GRID_POW = $gridpower; // Fegas 10 cannnot handle this, FW issue
	$GRID_POW = ($pv1power+ $pv2power); // So actual power will be taken from DC
	$ACV1   = $gridvolt1;
	$ACV2   = $gridvolt2;
	$ACV3   = $gridvolt3;
	$ACC1  = round($gridamps1,6);
	$ACC2  = round($gridamps2,6);
	$ACC3  = round($gridamps3,6);
	$ACF   = $gridfreq;
	$INTEMP= $intemp;
	$BOOT = $maxtemp;
	$DCINV1 = $pv1volt;
	$DCINV2 = $pv2volt;
	$DCINC1 = $pv1amps;
	$DCINC2 = $pv2amps;
	$DCPOW1  = $pv1power;
	$DCPOW2  = $pv2power;
	$BATTPOWER = ($battvolt*$battamps);

	if($debug) logging("DEBUG Wert ACV1: $ACV1");
	if($debug) logging("DEBUG Wert ACV2: $ACV2");
	if($debug) logging("DEBUG Wert ACV3: $ACV3");
	if($debug) logging("DEBUG Wert ACC1: $ACC1");
	if($debug) logging("DEBUG Wert ACC2: $ACC2");
	if($debug) logging("DEBUG Wert ACC3: $ACC3");
	if($debug) logging("DEBUG Wert ACF: $ACF");
	if($debug) logging("DEBUG Wert INTEMP: $INTEMP");
        if($debug) logging("DEBUG Wert BOOT: $BOOT");
	if($debug) logging("DEBUG Wert DCINV1: $DCINV1");
	if($debug) logging("DEBUG Wert DCINV2: $DCINV2");
	if($debug) logging("DEBUG Wert DCINC1: $DCINC1");
	if($debug) logging("DEBUG Wert DCINC2: $DCINC2");
	if($debug) logging("DEBUG Wert DCPOW1: $DCPOW1");
	if($debug) logging("DEBUG Wert DCPOW2: $DCPOW2");
	if($debug) logging("DEBUG Wert BATTV: $battvolt");
	if($debug) logging("DEBUG Wert BATTCHAMP: $battamps");
	if($debug) logging("DEBUG Wert BATTCAP: $battcap");
        if($debug) logging("DEBUG Wert BATTPOWER: $BATTPOWER");
	if($debug) logging("DEBUG: ges. PV in KWh: $pv_ges");
	if($debug) logging("DEBUG: akt. Leistung in Watt: $GRID_POW");
	if($debug) logging("DEBUG: Batterie Status: $battstat");

	//schreibe akt. Daten in Files, die wiederum von 123solar drei Mal pro Sek. abgefragt werden:
	write2file($tmp_dir."PV_GES.txt",$pv_ges);
	write2file($tmp_dir."ACV1.txt",$ACV1);
	write2file($tmp_dir."ACV2.txt",$ACV2);
	write2file($tmp_dir."ACV3.txt",$ACV3);
	write2file($tmp_dir."ACC1.txt",$ACC1);
	write2file($tmp_dir."ACC2.txt",$ACC2);
	write2file($tmp_dir."ACC3.txt",$ACC3);
	write2file($tmp_dir."ACF.txt",$ACF);
	write2file($tmp_dir."INTEMP.txt",$INTEMP);
	write2file($tmp_dir."BOOT.txt",$BOOT);
	write2file($tmp_dir."DCINV1.txt",$DCINV1);
	write2file($tmp_dir."DCINV2.txt",$DCINV2);
	write2file($tmp_dir."DCINC1.txt",$DCINC1);
	write2file($tmp_dir."DCINC2.txt",$DCINC2);
	write2file($tmp_dir."DCPOW1.txt",$DCPOW1);
	write2file($tmp_dir."DCPOW2.txt",$DCPOW2);
	write2file($tmp_dir."GRIDPOW.txt",$GRID_POW);
	write2file($tmp_dir."BATTV.txt",$battvolt);
	write2file($tmp_dir."BATTCAP.txt",$battcap);
	write2file($tmp_dir."BATTCHAMP.txt",$battamps);
	write2file($tmp_dir."BATTPOWER.txt",$BATTPOWER);
	write2file_string($tmp_dir."STATE.txt",$modusT);
	write2file_string($tmp_dir."BATTSTAT.txt",$battstat);
	write2file_string($tmp_dir."ts.txt",date("Ymd-H:i:s",$ts));
	//Verbindung zu WR abbauen
	fclose($fp);
	// Warte bis naechster Durchlauf
	sleep($warte_bis_naechster_durchlauf);
}
// END OF MAIN LOOP

// Div. Funtionen zur Datenaufbereitung
function hex2str($hex) 
{
    $str = '';
    for($i=0;$i<strlen($hex);$i+=2) $str .= chr(hexdec(substr($hex,$i,2)));
    return $str;
}
function cal_crc_half($pin)
{
	$sum = 0;
	for($i = 0; $i < strlen($pin); $i++)
	{
		$sum += ord($pin[$i]);
	}
	$sum = $sum % 256;
	if(strlen($sum)==2) $sum="0".$sum;
	if(strlen($sum)==1) $sum="00".$sum;
	return $sum;
}
function write2file($filename, $value)
{
	global $is_error_write, $log2console;
	$fp2 = fopen($filename,"w");
	if(!$fp2 || !fwrite($fp2, (float) $value))
	{
		if(!$is_error_write)
		{
				logging("Fehler beim Schreiben in die Datei $filename Start (weitere Meldungen werden unterdrueckt)!", true);
		}
		$is_error_write = true;
	}
	else if($is_error_write)
	{
		logging("Fehler beim Schreiben bereinigt!", true);
		$is_error_write = false;
	}
	fclose($fp2);
}
function write2file_string($filename, $value)
{
	global $is_error_write, $log2console;
	$fp2 = fopen($filename,"w");
	if(!$fp2 || !fwrite($fp2, $value))
	{
		if(!$is_error_write)
		{
			logging("Fehler beim Schreiben in die Datei $filename Start (weitere Meldungen werden unterdrueckt)!", true);
		}
		$is_error_write = true;
	}
	else if($is_error_write)
	{
		logging("Fehler beim Schreiben bereinigt!", true);
		$is_error_write = false;
	}
	fclose($fp2);
}
function logging($txt, $write2syslog=false)
{
	global $fp_log, $log2console, $debug, $ts;
	if($log2console) echo date("Y-m-d H:i:s").": $txt<br />\n";
	if($debug)
	{
//              list($ts,$ms) = explode(".",microtime(true));
		list($ts) = explode(".",microtime(true));
		$dt = new DateTime(date("Y-m-d H:i:s.",$ts));
		echo $dt->format("Y-m-d H:i:s.u");
		$logdate = $dt->format("Y-m-d H:i:s.u");
		echo date("Y-m-d H:i:s").": $txtread_bytes_usb\n";
		fwrite($fp_log, date("Y-m-d H:i:s").": $txt<br />\n");
//                fwrite($fp_log, $logdate.": $txt<br />\n");
//                if($write2syslog) syslog(LOG_ALERT,$txt);
	}
}
function batterie_nacht() 
{
	// Nachts NUR die Werte der Batterie abfragen
	global $debug, $err, $fp, $tmp_dir, $warte_bis_naechster_durchlauf;
	$byte=send_and_get_response($fp, "^P003GS");

	// Batteriedaten auswerten + pruefen
	$array = array_map("floatval",explode(",", $byte));
	$battvolt = ($array[4])/10;
	// $battvolt = (substr($byte,27,4)/10);
	$battcap = ($array[5]);
	// $battcap = substr($byte,32,3);
	$battamps = ($array[6]);
	// $battamps = substr($byte,36,6);
	$batttemp = ($array[count($array) - 1]);
	// Power State abfragen
	$byte=send_and_get_response($fp, "^P003PS");
	$byte = array_map("floatval", explode(",", $byte));
	$battcode_code = $array[15];//substr($byte,75,1);
  	if($battcode_code==0) $battstat="donothing";
	if($battcode_code==1) $battstat="charge";
	if($battcode_code==2) $battstat="discharge";

	// Werte in die Files schreiben
	write2file($tmp_dir."BATTV.txt",$battvolt);
	write2file($tmp_dir."BATTCAP.txt",$battcap);
	write2file_string($tmp_dir."BATTSTAT.txt",$battstat);
	write2file_string($tmp_dir."BATTCODE.txt",$battcode);
	write2file($tmp_dir."BATTCHAMP.txt",$battamps);
	if($debug) logging("DEBUG Wert BATTV: $battvolt");
	if($debug) logging("DEBUG Wert BATTCHAMP: $battchamp");
	if($debug) logging("DEBUG Wert BATTCAP: $battcap");
	if($debug) logging("DEBUG Wert BATTTEMP: $batttemp"); 
	fclose($fp);
}

function getalarms()
{
	global $debug, $debug2, $tmp_dir, $device;
// 	$moxa_ip = "192.168.123.115";
// 	$moxa_port = 20108;
	$moxa_timeout = 10;
	$fp = @fopen($device, 'rbw+');
// 	$fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout);
	// Read fault register
	// Command: ^P003WS<cr>: Query warning status
	//                                1 1 1 1 1 1 1 1 1 1 2 2
	//            0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
	//Answer:^D040A,B,C,D,E,F,G,H,I,J,K,L,M,N,O,P,Q,R,S,T,U,V<CRC><cr>
	$warnings = send_and_get_response($fp, "^P003WS"); //Device Warning Status inquiry
	//echo "Warnings:".$warnings."\n";
	$warning_list = array(
		"Solar input 1 loss",
		"Solar input 2 loss",
		"Solar input 1 voltage too higher",
		"Solar input 2 voltage too higher",
		"Battery under",
		"Battery low",
		"Battery open",
		"Battery voltage too high",
		"Battery low in hybrid mode",
		"Grid voltage high loss",
		"Grid voltage low loss",
		"Grid frequency high loss",
		"Grid frequency low loss",
		"AC input long-time average voltage over",
		"AC input voltage loss",
		"AC input frequency loss",
		"AC input island",
		"AC input phase dislocation",
		"Over temperature",
		"Over load",
		"EPO Active",
		"AC input wave loss",
	);
	$warnings = explode(",", $warnings);
	for ($i = 0; $i < count($warnings); ++$i) {
		$bit = $warnings[$i];
		if($debug) echo "Bit ".$i." is ".$bit."\n";
		if ($bit) {
			$error[$i] = $warning_list[$i];
		}
	}

	if ($debug)  {
		for($i = 0; $i < count($warnings); $i++) {
			if(isset($error[$i])) echo "Fehler:".$error[$i];
		}
	}
	
	$fp = fopen($tmp_dir.'ALARM.txt',"w");
	if($fp)
	{
		for($i = 0; $i < 22; $i++)
		{
			if(isset($error[$i]))
			{
				fwrite($fp, $error[$i]."\n");
			}
		}
	}
	fclose($fp);
}

function send_and_get_response($handle, $data) {
	write_bytes_usb($handle, $data);
	$resp = read_bytes_usb($handle);
	if ($resp == "") {
		logging("Invalid request: $data \n");
	}
	return $resp;
}



function write_bytes_usb($handle, $bytes) {
	$current_payload = "";
	echo "Request: $bytes\n";
	while(strlen($bytes) >= 8) {
		$current_payload = substr($bytes, 0, 8);
		fwrite($handle, $current_payload);
		fflush($handle);
		$bytes = substr($bytes, 8);
	}
	$bytes = $bytes.chr(0x0d);
	if (strlen($bytes) == 1) {
		$bytes = $bytes.substr($current_payload, strlen($bytes), 8);
	}
	fwrite($handle, $bytes);
	fflush($handle);
}

function read_byte_by_byte_from_usb($handle) {
	$result = "";
	$temp = "";
	do {
		// echo "read_byte_by_byte_from_usb loop\n";
		$temp = fgetc($handle);
		$result = $result.$temp;
		if (strpos($temp, "\r") !== FALSE){
			break;
		}
		if (strlen($result)==8) {
			break;
		}

	} while (TRUE);
	// echo "read_byte_by_byte_from_usb  result: ".bin2hex($result);
	return $result;
}


function read_bytes_usb($handle) {
	$result = "";
	$temp = "";
	do {
		$temp = read_byte_by_byte_from_usb($handle);
		$result = $result.$temp;
		// echo bin2hex($temp)."\n";
		if (strpos($temp, "\r") !== FALSE){
			break;
		}

	} while (TRUE);
	$result = substr($result, 0, strpos($result, "\r"));
	// echo "Response before validation: $result\n";
	if (is_valid_response($result)) {
		$result = strip_protocol_from_response($result);
		echo "Returning combined result: ".$result."\n";
		return $result;
	} else {
		return "";
	}
}

function is_valid_response($response) {
	if (strpos($response, "^0") === 0) {
		return false;
	} else if(strpos($response, "NAK(") !== FALSE) {
		return false;
	}
	return true;
}

function strip_protocol_from_response($response) {
	// echo "strip_protocol_from_response   input: ".$response."\n";
	//^DXXX
	$temp = substr($response, 5);
	// echo "$temp\n";
	return substr($temp, 0, strlen($temp) - 2);
}


?>

