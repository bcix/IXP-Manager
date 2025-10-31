<?php
use IXP\Models\VlanInterface;
use IXP\Models\IPv4Address;
use IXP\Models\IPv6Address;
?>
<?php $vlan_intf_id = 480 // insert vlaninterface id of translator here: 484 at rs01, 480 at rs02 ?>
<?php $formatted_vlan_intf_id = sprintf( '%04d', $vlan_intf_id ) // vlaninterface id formatted ?>
<?php $vlan_interface = VlanInterface::find($vlan_intf_id) ?>
<?php $ipv4addr = IPv4Address::find($vlan_interface['ipv4addressid'])['address'] ?>
<?php $ipv6addr = IPv6Address::find($vlan_interface['ipv6addressid'])['address'] ?>

########################################################################################
########################################################################################
###
### AS4200008950 - BCIX RFC8950 translator01 - VLAN Interface #<?= $vlan_intf_id ?>

filter f_export_as4200008950 {
    if ! (ixp_community_filter( 4200008950 ) ) then reject;

    if bgp_large_community ~ [( routeserverasn, 1101, * )] then reject;

    #####Graceful BGP Session Shutdown####
    if (65535, 0) ~ bgp_community then {
        bgp_local_pref = 0;
    }
    accept;
}

filter f_import_as4200008950 {
    bgp_path.delete( 4200008950 );
    accept;
}

protocol bgp pb_<?= $formatted_vlan_intf_id ?>_as4200008950 from tb_rsclient {
    description "AS4200008950 - BCIX RFC8950 translator01";
<?php   if( $t->router->protocol == 6 ): ?>
    neighbor <?= $ipv6addr ?> as 4200008950;
<?php   else: ?>
    neighbor <?= $ipv4addr ?> as 4200008950;
<?php   endif; ?>
    ipv4 {
<?php   if( $t->router->protocol == 6 ): ?>
        extended next hop on;
<?php   endif; ?>
        table master4;
        import table on;  # Automatic channel reloads based on RPKI changes
        import limit 200000 action restart;
        import filter f_import_as4200008950;
        export filter f_export_as4200008950;
        import keep filtered on;
        ###add-path support RFC7911###
        add paths tx;
    };
    passive on;
    interpret communities off;  # enable rfc1997 well-known community pass through
<?php   if( $t->router->protocol == 6 ): ?>
    password "<?= $vlan_interface['ipv6bgpmd5secret'] ?>";
<?php   else: ?>
    password "<?= $vlan_interface['ipv4bgpmd5secret'] ?>";
<?php   endif; ?>
}