<?php
/***********************************************
* File      :   gab-sync.php
* Project   :   Z-Push - tools - GAB sync
* Descr     :   GAB-Sync tool.
*
* Created   :   28.01.2016
*
* Copyright 2016 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
* ************************************************/

if (!defined('SYNC_CONFIG')) define('SYNC_CONFIG', 'config.php');
include_once(SYNC_CONFIG);

/************************************************
 * MAIN
 */
    define('BASE_PATH_CLI',  dirname(__FILE__) ."/");
    set_include_path(get_include_path() . PATH_SEPARATOR . BASE_PATH_CLI);
    try {
        GabSyncCLI::CheckEnv();
        GabSyncCLI::CheckOptions();

        if (! GabSyncCLI::SureWhatToDo()) {
            // show error message if available
            if (GabSyncCLI::GetErrorMessage())
                fwrite(STDERR, GabSyncCLI::GetErrorMessage() . PHP_EOL.PHP_EOL);

            echo GabSyncCLI::UsageInstructions();
            exit(1);
        }
        else if (!GabSyncCLI::SetupSyncWorker()) {
            fwrite(STDERR, GabSyncCLI::GetErrorMessage() . PHP_EOL);
            exit(1);
        }

        GabSyncCLI::RunCommand();
    }
    catch (Exception $ex) {
        die(get_class($ex) . ": ". $ex->getMessage() . PHP_EOL);
    }



/************************************************
 * GAB SYNC CLI
 */
class GabSyncCLI {
    const COMMAND_SIMULATE = 1;
    const COMMAND_SYNC = 2;
    const COMMAND_SYNC_ONE = 3;
    const COMMAND_CLEARALL = 4;
    const COMMAND_DELETEALL = 5;

    static private $syncWorker;
    static private $command;
    static private $uniqueId = false;
    static private $errormessage;

    /**
     * Returns usage instructions.
     *
     * @access public
     * @return string
     */
    static public function UsageInstructions() {
        return  "Usage:" .PHP_EOL.
                "\tgab-sync.php -a ACTION [options]" .PHP_EOL.PHP_EOL.
                "Parameters:" .PHP_EOL.
                 "\t-a simulate | sync | sync-one | clear-all | delete-all" .PHP_EOL.
                 "\t[-u] UNIQUE-ID" .PHP_EOL.PHP_EOL.
                "Actions:" .PHP_EOL.
                "\tsimulate\t\t Simulates the GAB synchronization and prints out statistics and configuration suggestions." .PHP_EOL.
                "\tsync\t\t\t Synchronizes all data from the GAB to the global folder, clearing all existing data if configuration changed!" .PHP_EOL.
                "\tsync-one -u UNIQUE-ID\t Tries do find the entry with UNIQUE-ID and updates the chunk this entry belongs to." .PHP_EOL.
                "\tclear-all\t\t Removes all data from the global folder." .PHP_EOL.
                "\tdelete-all\t\t Like clear-all but also deletes the global folder." .PHP_EOL.
                PHP_EOL;
    }

    /**
     * Setup of the SyncWorker implementation.
     *
     * @access public
     * @return boolean
     */
    static public function SetupSyncWorker() {
        $file = "lib/" .strtolower(SYNCWORKER).".php";

        if (!file_exists($file)) {
            self::$errormessage = "SyncWorker file '".$file."' can not be found. Check your configuration.";
            return false;
        }
        else {
            include_once($file);
        }

        if (!class_exists(SYNCWORKER)) {
            self::$errormessage = "SyncWorker file loaded, but class '".SYNCWORKER."' can not be found. Check your implementation.";
        }
        else {
            $s = @constant('SYNCWORKER');
            self::$syncWorker = new $s();
            return true;
        }
        return false;
    }

    /**
     * Checks the environment.
     *
     * @access public
     * @return void
     */
    static public function CheckEnv() {
        if (php_sapi_name() != "cli")
            self::$errormessage = "This script can only be called from the CLI.";

        if (!function_exists("getopt"))
            self::$errormessage = "PHP Function getopt not found. Please check your PHP version and settings.";
    }

    /**
     * Checks the options from the command line.
     *
     * @access public
     * @return void
     */
    static public function CheckOptions() {
        if (self::$errormessage)
            return;

        $options = getopt("u:a:");

        // get 'unique-id'
        if (isset($options['u']) && !empty($options['u']))
            self::$uniqueId = strtolower(trim($options['u']));
        else if (isset($options['unique-id']) && !empty($options['unique-id']))
            self::$uniqueId = strtolower(trim($options['unique-id']));

        // get 'action'
        $action = false;
        if (isset($options['a']) && !empty($options['a']))
            $action = strtolower(trim($options['a']));
        elseif (isset($options['action']) && !empty($options['action']))
            $action = strtolower(trim($options['action']));

        // get a command for the requested action
        switch ($action) {
            // simulate
            case "simulate":
                self::$command = self::COMMAND_SIMULATE;
                break;

            // sync!
            case "sync":
                self::$command = self::COMMAND_SYNC;
                break;

            // sync one
            case "sync-one":
                if (self::$uniqueId === false) {
                    self::$errormessage = "Not possible to synchronize one user, if the unique-id is not specified (-u parameter).";
                }
                else {
                    self::$command = self::COMMAND_SYNC_ONE;
                }
                break;

            // clear all data
            case "clear-all":
                self::$command = self::COMMAND_CLEARALL;
                break;

            // delete all
            case "delete-all":
                self::$command = self::COMMAND_DELETEALL;
                break;

            default:
                self::UsageInstructions();
        }
    }

    /**
     * Indicates if the options from the command line
     * could be processed correctly.
     *
     * @access public
     * @return boolean
     */
    static public function SureWhatToDo() {
        return isset(self::$command);
    }

    /**
     * Returns a errormessage of things which could have gone wrong.
     *
     * @access public
     * @return string
     */
    static public function GetErrorMessage() {
        return (isset(self::$errormessage))?self::$errormessage:"";
    }

    /**
     * Runs a command requested from an action of the command line.
     *
     * @access public
     * @return void
     */
    static public function RunCommand() {
        echo PHP_EOL;
        switch(self::$command) {
            case self::COMMAND_SIMULATE:
                self::$syncWorker->Simulate();
                break;

            case self::COMMAND_SYNC:
                self::$syncWorker->Sync();
                break;

            case self::COMMAND_SYNC_ONE:
                self::$syncWorker->SyncOne(self::$uniqueId);
                break;

            case self::COMMAND_CLEARALL:
                echo "Are you sure you want to remove all chunks and data from the public folder. ALL GAB data will be removed from ALL KOE instances [y/N]: ";
                $confirm  =  strtolower(trim(fgets(STDIN)));
                if ( $confirm === 'y' || $confirm === 'yes')
                    self::$syncWorker->ClearAll();
                else
                    echo "Aborted!".PHP_EOL;
                break;

            case self::COMMAND_DELETEALL:
                echo "Are you sure you want to remove all chunks and data from the public folder and delete it? ALL GAB data will be removed from ALL KOE instances [y/N]: ";
                $confirm  =  strtolower(trim(fgets(STDIN)));
                if ( $confirm === 'y' || $confirm === 'yes')
                    self::$syncWorker->DeleteAll();
                else
                    echo "Aborted!".PHP_EOL;
                break;
        }
        echo PHP_EOL;
    }

}