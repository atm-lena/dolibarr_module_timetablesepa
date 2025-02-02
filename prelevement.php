<?php
/* Copyright (C) 2019 ATM Consulting <support@atm-consulting.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require 'config.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('paymentschedule/class/paymentschedule.class.php');
dol_include_once('paymentschedule/lib/paymentschedule.lib.php');
require_once DOL_DOCUMENT_ROOT.'/compta/prelevement/class/bonprelevement.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/bank.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/prelevement.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

// Load translation files required by the page
$langs->loadLangs(array('paymentschedule@paymentschedule', 'banks', 'categories', 'widthdrawals', 'companies', 'bills'));


//$result = restrictedArea($user, 'prelevement', '', '', 'bons');
if(empty($user->rights->paymentschedule->write)) accessforbidden();

$action = GETPOST('action');


$hookmanager->initHooks(array('paymentschedulecard', 'globalcard'));



/*
 * Actions
 */

$parameters = array('id' => $id, 'ref' => $ref);
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some
if ($reshook < 0) setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');

if (empty($reshook))
{
    if ($action === 'searchpaymentschedule')
    {
        $date_demande_start = dol_mktime(0, 0, 0, GETPOST('remonth'), GETPOST('reday'), GETPOST('reyear'));
        $date_demande_end = dol_mktime(23, 59, 59, GETPOST('remonth'), GETPOST('reday'), GETPOST('reyear'));
		$TPaiementId = !empty($conf->global->PAYMENTSCHEDULE_MODE_REGLEMENT_TO_USE) ? explode(',', $conf->global->PAYMENTSCHEDULE_MODE_REGLEMENT_TO_USE) : array();

		if ($date_demande_start && !empty($TPaiementId))
        {
            // TODO mettre le fk_mode_reglement en conf
            $sql = 'SELECT COUNT(*) AS nb 
                    FROM '.MAIN_DB_PREFIX.'paymentscheduledet td
                    INNER JOIN '.MAIN_DB_PREFIX.'paymentschedule t ON (t.rowid = td.fk_payment_schedule)
                    WHERE t.status = '.PaymentSchedule::STATUS_VALIDATED.'
                    AND td.status = '.PaymentScheduleDet::STATUS_WAITING.'
                    AND td.fk_mode_reglement IN ('.$conf->global->PAYMENTSCHEDULE_MODE_REGLEMENT_TO_USE.')
                    AND td.date_demande >= \''.$db->idate($date_demande_start).'\' AND td.date_demande <= \''.$db->idate($date_demande_end).'\'';
            $resql = $db->query($sql);

            if ($resql)
            {
                $obj = $db->fetch_object($resql);
                $number_det_found = $obj->nb;
            }
        }
		else
		{
			setEventMessage($langs->trans('PaymentSchedule_Warnings_checkDateOrPaymentType'), 'warnings');
		}
    }
    elseif ($action === 'createpaymentschedule')
    {
        $date_demande_start = GETPOST('date_demande_start');
        $date_demande_end = GETPOST('date_demande_end');
		$TPaiementId = !empty($conf->global->PAYMENTSCHEDULE_MODE_REGLEMENT_TO_USE) ? explode(',', $conf->global->PAYMENTSCHEDULE_MODE_REGLEMENT_TO_USE) : array();

		if (!empty($TPaiementId))
		{
			// TODO mettre le fk_mode_reglement en conf
			$sql = 'SELECT t.fk_facture, td.rowid
                FROM '.MAIN_DB_PREFIX.'paymentscheduledet td
                INNER JOIN '.MAIN_DB_PREFIX.'paymentschedule t ON (t.rowid = td.fk_payment_schedule)
                WHERE t.status = '.PaymentSchedule::STATUS_VALIDATED.'
                AND td.status = '.PaymentScheduleDet::STATUS_WAITING.'
                AND td.fk_mode_reglement IN ('.$conf->global->PAYMENTSCHEDULE_MODE_REGLEMENT_TO_USE.')
                AND td.date_demande >= \''.$db->idate($date_demande_start).'\' AND td.date_demande <= \''.$db->idate($date_demande_end).'\'';
			$resql = $db->query($sql);

			$errors = array();
			if ($resql)
			{
				$nb_create = $nb_error = 0;
				while ($obj = $db->fetch_object($resql))
				{
					$facture = new Facture($db);
					$facture->fetch($obj->fk_facture);

					$det = new PaymentScheduleDet($db);
					$det->fetch($obj->rowid);

//                var_dump($facture->ref, $det->id);exit;
					$db->begin();

					$result = $facture->demande_prelevement($user, $det->amount_ttc);
//                var_dump($result, $facture->error);exit;
					if ($result > 0)
					{
						$sql = 'SELECT MAX(rowid) as last_id FROM '.MAIN_DB_PREFIX.'prelevement_facture_demande';
						$resql = $db->query($sql);
						if ($resql)
						{
							$obj = $db->fetch_object($resql);
							if ($obj)
							{
								$res = $det->setInProcess($user, $obj->last_id);
								if ($res > 0)
								{
									$nb_create++;
									$db->commit();
								}
								else
								{
									$nb_error++;
									$db->rollback();
									$errors = array_merge($errors, $det->errors);
								}
							}
						}
						else
						{
							$nb_error++;
							$db->rollback();
							$errors[] = $db->lasterror();
						}

					}
					else
					{
						$nb_error++;
						$db->rollback();
						$errors[] = $facture->error;
					}
				}

				if ($nb_create > 0) setEventMessage($langs->trans('PaymentSchedule_requestNbCreate', $nb_create));
				if ($nb_error > 0) setEventMessage($langs->trans('PaymentSchedule_requestNbError', $nb_error), 'errors');

				header('Location: '.$_SERVER['PHP_SELF']);
				exit;
			}
		}
		else
		{
			setEventMessage($langs->trans('PaymentSchedule_Warnings_checkDateOrPaymentType'), 'warnings');
		}
	}
}

/**
 * View
 */
$form = new Form($db);

$title=$langs->trans('PaymentSchedule');
llxHeader('', $title);


print load_fiche_titre($langs->trans('NewPaymentSchedule_createRequests'), '', 'paymentschedule@paymentschedule');

dol_fiche_head(array(), '');

// ...
if ($action === 'searchpaymentschedule')
{
    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
    print '<input type="hidden" name="token" value="'.$_SESSION['newtoken'].'">';
    print '<input type="hidden" name="action" value="createpaymentschedule">';
    print '<input type="hidden" name="date_demande_start" value="'.$date_demande_start.'">';
    print '<input type="hidden" name="date_demande_end" value="'.$date_demande_end.'">';

    print '<table class="border centpercent">'."\n";

    print '<tr>';
    print '<td class="titlefield selectdate">'.$langs->trans('PaymentSchedule_requestSelectDate').'</td>';
    print '<td>'.dol_print_date($date_demande_start, 'day').'</td>';
    print '</tr>';

    print '</table>'."\n";

    print '<div class="info">'.$langs->trans('PaymentSchedule_requestNumRowsFound', (int) $number_det_found).'</div>';

    print '<div class="center">';
    if ($number_det_found > 0) print '<input type="submit" class="button" name="add" value="'.dol_escape_htmltag($langs->trans('Create')).'">';
    else print '<a class="button" href="'.$_REQUEST['PHP_SELF'].'">'.$langs->trans('PaymentSchedule_requestBack').'</a>';
    print '</div>';

    print '</form>';
}
else
{
    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
    print '<input type="hidden" name="token" value="'.$_SESSION['newtoken'].'">';
    print '<input type="hidden" name="action" value="searchpaymentschedule">';

    print '<table class="border centpercent">'."\n";

    print '<tr>';
    print '<td class="titlefield selectdate">'.$langs->trans('PaymentSchedule_requestSelectDate').'</td>';
    print '<td>'.$form->selectDate().'</td>';
    print '</tr>';

    print '</table>'."\n";


    print '<div class="center">';
    print '<input type="submit" class="button" name="search" value="'.dol_escape_htmltag($langs->trans('Search')).'">';
    print '</div>';

    print '</form>';
}


dol_fiche_end();


llxFooter();
$db->close();
