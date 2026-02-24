
########################################################################################
########################################################################################
###
### AS4200008950 - BCIX RFC8950 translator01 - VLAN Interface #480

filter f_export_as4200008950 {
    if (routeserverasn, 0, 0) ~ bgp_large_community then
        reject;
    if (0, routeserverasn) ~ bgp_community then
        reject;

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

protocol bgp pb_0480_as4200008950 from tb_rsclient {
    description "AS4200008950 - BCIX RFC8950 translator01";
<?php   if( $t->router->protocol == 6 ): ?>
    neighbor 2001:7f8:19:1::3ff6:fa as 4200008950;
<?php   else: ?>
    neighbor 193.178.185.250 as 4200008950;
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
    password "6TUshwN6BdTR";
}
