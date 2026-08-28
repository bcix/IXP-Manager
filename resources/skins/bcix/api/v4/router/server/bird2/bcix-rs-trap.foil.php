<?php
/*
 * Bird Route Server Configuration Template
 *
 *
 * You should not need to edit these files - instead use your own custom skins. If
 * you can't effect the changes you need with skinning, consider posting to the mailing
 * list to see if it can be achieved / incorporated.
 *
 * Skinning: https://ixp-manager.readthedocs.io/en/latest/features/skinning.html
 *
 * Copyright (C) 2009 - 2019 Internet Neutral Exchange Association Company Limited By Guarantee.
 * All Rights Reserved.
 *
 * This file is part of IXP Manager.
 *
 * IXP Manager is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, version v2.0 of the License.
 *
 * IXP Manager is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License v2.0
 * along with IXP Manager.  If not, see:
 *
 * http://www.gnu.org/licenses/gpl-2.0.html
 */
?>
<?php
use IXP\Models\VirtualInterface;
use IXP\Models\SwitchPort;
?>
<?php
$format = "%-20s %-25s %-20s %39s %10s %-20s\n";
echo sprintf(
    $format,
    '## Switch',
    'Interface',
    'Protocol',
    'IP Address',
    'ASN',
    'Member'
);

foreach ($t->ints as $intf) {
    // skip the routeserver
    if ($intf['autsys'] == $t->router->asn) {
        continue;
    }

    $virtual_interface = VirtualInterface::find($intf['viid']);
    $intf_name = '';

    // customer with LAG
    if ($virtual_interface->channelgroup) {
        $intf_name = 'Port-Channel' . $virtual_interface->channelgroup;
    // customer with standalone ports
    } else {
        $phys_intf_count = $virtual_interface->physicalInterfaces->count();
        // just a single interface
        if ($phys_intf_count == 1) {
            $physicalInterface = $virtual_interface->physicalInterfaces->first();
            $intf_name = SwitchPort::find($physicalInterface->switchportid)?->name;
        // port migration/upgrade case
        } else if ($phys_intf_count == 2) {
            $physicalInterface_1 = $virtual_interface->physicalInterfaces->get(0);
            $physicalInterface_2 = $virtual_interface->physicalInterfaces->get(1);
            // only first intf connected and active
            if ($physicalInterface_1->status === 1 and $physicalInterface_2->status !== 1){
                $intf_name = SwitchPort::find($physicalInterface_1->switchportid)?->name;
            // only second intf connected and active
            } else if ($physicalInterface_1->status !== 1 and $physicalInterface_2->status === 1){
                $intf_name = SwitchPort::find($physicalInterface_2->switchportid)?->name;
            // both active (switch migration)
            } else{
                continue;
            }
        // too many physical interfaces
        } else {
            continue; 
        }
    }

    echo sprintf(
        $format,
        $intf['sname'],
        $intf_name,
        'pb_' . $intf['fvliid'] . '_as' . $intf['autsys'],
        $intf['address'],
        $intf['autsys'],
        $intf['cname']
    );
}
?>
##
## END_OF_CONFIG_MARKER_FOR_<?= $t->handle . "\n" ?>
## Generated: <?= date('Y-m-d H:i:s') . "\n" ?>