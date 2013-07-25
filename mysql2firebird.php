#!/usr/bin/php
<?php
// Copyright (c) 2011 Milan Babuskov (mbabuskov@yahoo.com)
// This software is released under GNU GPL licence version 2, please see the
// file COPYING for details

if ($argc != 2)
{
    exit("\nUsage: mysql2firebird.php filename.sql\n");
}

$tablenamecnt=0; //variable to count prepended table names to make unique

// split the script into statements and process each one
$statements = explode(';', file_get_contents($argv[1]));
foreach ($statements as $s)
{
//echo "<pre>"; //debug, print the input statement
//var_dump($s);
//echo "</pre>";

//    $s = str_replace('`', '"', $s);
    $s = str_replace('`', '', $s); //use unquoted identifiers
    $s = str_replace('ENGINE=MyISAM', '', $s);
    $s = str_replace('ENGINE=InnoDB', '', $s);
    $s = str_replace('ENGINE = MyISAM', '', $s);
    $s = str_replace('ENGINE = InnoDB', '', $s);
    $s = str_replace('SET SQL_MODE', '-- SET SQL_MODE', $s);
    $s = str_replace('SHOW WARNINGS', '', $s);

    // could be done better
    $s = str_replace('DEFAULT CHARSET=utf8', '', $s);
    $s = str_replace('COLLATE=utf8_unicode_ci', '', $s);
    $s = str_replace('collate utf8_unicode_ci', '', $s);
    $s = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TABLE', $s);
    $s = str_replace('CREATE  TABLE IF NOT EXISTS', 'CREATE TABLE', $s);
    $s = str_replace('DROP TABLE IF EXISTS', 'DROP TABLE', $s);
    $s = str_replace(' unsigned', ' /* WARNING: unsigned */', $s);
    $s = str_replace('ASC', '', $s); //default is ASCending order
    $s = str_replace('ZEROFILL', 'DEFAULT 0', $s);
	

    // convert datatypes
    $s = str_replace(' int(11)', ' integer', $s);
    $s = str_replace(' int(10)', ' integer', $s);
    $s = str_replace(' tinyint(4)', ' smallint /* WARNING: tinyint */', $s);
    $s = str_replace(' TINYINT(1)', ' smallint /* WARNING: tinyint */', $s);
    $s = str_replace(' mediumint(4)', ' integer /* WARNING: mediumint */', $s);
    $s = str_replace(' mediumint(8)', ' integer /* WARNING: mediumint */', $s);
    $s = str_replace(' smallint(6)', ' smallint', $s);
    $s = str_replace(' bigint(20)', ' bigint', $s);
    $s = str_replace(' datetime', ' timestamp', $s);
    $s = str_replace(' longtext', ' varchar(8000)', $s);
    $s = str_replace(' text', ' varchar(8000)', $s);

	//remove mysql workbench lines
    if (strpos($s, 'CREATE SCHEMA') !== false) $s = '';
    if (strpos($s, 'USE ') !== false) $s = '';
    if (strpos($s, '@OLD') !== false) $s = '';
	
    // break statements into lines
    $sp = explode("\n", $s);
    $ac_field = false; //auto increment variable
    $ac_value = 0;
    $ts_field = false; //timestamp variable
    $keys = array();
    $lines = array();
    foreach ($sp as $line)
    {
//echo "<pre1>"; //debug, print each input line
//var_dump($line);
//echo "</pre1>";

        if (strpos($line, 'NOT NULL') !== false)
        {
            $line = trim(str_replace('NOT NULL', '', $line));
            $tline = trim($line, ',');
            if ($tline == $line)
                $line .= 'NOT NULL';
            else
                $line = $tline.' NOT NULL,';
        }
		// NULL may be used as a default value
		else if (strpos($line, 'NULL') !== false)
        {
            $line = str_replace('NULL', 'DEFAULT NULL', $line);
        } 

        // break line into fields
        $fields = explode(' ', trim($line));
        if (strpos($line, 'CREATE TABLE') !== false)
        {
            $tablename = trim($fields[2]); //extract table name
            $table = str_replace('"', '', $tablename);
        }

        if (strpos($line, 'AUTO_INCREMENT') !== false) // extract field name
            $ac_field = trim($fields[0]);
        $line = str_replace('AUTO_INCREMENT', '', $line);

        if (strpos($line, 'TIMESTAMP') !== false) // extract field name
            $ts_field = trim($fields[0]);

        $fields = explode(' ', trim($line));
        if ($fields[0] == 'INDEX')
        {
            $k = " create index idx_".$table.'_'.count($keys)." on $tablename ";
            unset($fields[1]);
            unset($fields[0]);
            $keys[] = trim($k.join(' ', $fields), ',');
            continue;
        }
		//Prepend part of tablename to index name if it is not already part of it. Helps it to be unique and not too long. Also, designer generated have a 'fk_' and are already unique.
        if ($fields[0] == 'CREATE' && $fields[1] == 'INDEX' && strpos($line, $table.'_') === false && strpos($line, 'fk_') === false)
        {
			$fields[2] = substr($table, 0, 4).$tablenamecnt++.'_'.$fields[2]; //prepend tablename to index name
        }
        if ($fields[0] == 'CREATE' && $fields[1] == 'UNIQUE' && strpos($line, $table.'_') === false)
        {
			$fields[3] = substr($table, 0, 4).$tablenamecnt++.'_'.$fields[3]; //prepend tablename to index name
        }
        if ($fields[0] == 'CONSTRAINT' && strpos($line, $table.'_') === false)
        {
			$fields[1] = substr($table, 0, 4).$tablenamecnt++.'_'.$fields[1]; //prepend tablename to constraint name
        }
        foreach ($fields as $ix => $f)
        {
            if (strpos($f, 'AUTO_INCREMENT=') !== false)
            {
                $p = explode('=', trim($f));
                $ac_value = $p[1];
                $fields[$ix] = '';
            }

            if (trim($f) == 'DEFAULT' && strpos($fields[$ix+1], 'CHARSET=') !== false)
            {
                $fields[$ix] = '';
                $fields[$ix+1] = '';
            }

//Unique cannot be used if it already a primary key columns, would need fancy code to differentiate.
//Allow unique if the field name is 'Name'
            if (trim($fields[0]) == 'UNIQUE' && trim($fields[$ix+1]) == 'INDEX')
            {
//echo "<pre2>"; //debug, print fields
//var_dump($fields);
//echo "</pre2>";
				if (strpos($fields[$ix+2], 'Name_') !== false)
				{
                $fields[$ix+1] = '';
                $fields[$ix+2] = '';
				}
				else
				{
                $fields[$ix] = ''; //wipe it all but leave a trailing " )"
                $fields[$ix+1] = '';
                $fields[$ix+2] = '';
                $fields[$ix+3] = '';
                $fields[$ix+4] = '';
				if (strpos($fields[$ix+5], ')') === false) $fields[$ix+5] = '';
//                $fields[$ix+4] = str_replace('ASC', '', $fields[$ix+4]); // ASC) -> )
				}
            }
            
            // set default values for firebird
if (strtoupper(trim($f)) == 'DEFAULT' && strpos($fields[$ix+1], 'CHARSET=') === false)
{
$fields[$ix+1] = str_replace(array(',','/'),'',$fields[$ix+1]);
$set_default_queries[] = "alter table ".$tablename." alter ".trim($fields[0])." set default '".$fields[$ix+1]."';";

$fields[$ix] = '';
$fields[$ix+1] = '';
}
        }
        $lines[] = trim(join(' ', $fields));
    }

    // remove trailing comma at the end of create table
    foreach ($lines as $ix => $l)
        if ($ix > 0 && substr($l, 0, 1) == ')') // end of CREATE TABLE
            $lines[$ix-1] = trim($lines[$ix-1], ',');

    $s = join("\n", $lines);
    if (trim($s) != '')
        echo $s.';';

    foreach ($keys as $k)
        echo "\n$k;";

		//If an AUTO_INCREMENT field, create a sequence and trigger for this table
		// to auto update it if it is null.
		if ($ac_field !== false)
    {
        echo "
create SEQUENCE gen_$table;
alter SEQUENCE gen_$table restart with $ac_value;
set term !! ;
create trigger t_$table for $tablename before insert position 0
as begin
if (new.$ac_field is null) then
new.$ac_field = gen_id(gen_$table, 1);
RDB\$SET_CONTEXT('USER_SESSION', 'LAST_INSERT_ID', new.$ac_field);
end!!
set term ; !!
";
    }
		//If a TIMESTAMP field, create a trigger to auto timestamp it if it is null.
		if ($ts_field !== false)
    {
        echo "
set term !! ;
create trigger ts_$table for $tablename before insert position 0
as begin
if (new.$ts_field is null) then
new.$ts_field = current_timestamp;
end!!
set term ; !!
";
    }
}
echo "
set term !! ;
CREATE PROCEDURE LAST_INSERT_ID RETURNS (ID BIGINT) AS
BEGIN
id = RDB\$GET_CONTEXT('USER_SESSION', 'LAST_INSERT_ID');
suspend;
END!!
set term ; !!
";

if (isset($set_default_queries))
    $output .= implode("\n",$set_default_queries);
