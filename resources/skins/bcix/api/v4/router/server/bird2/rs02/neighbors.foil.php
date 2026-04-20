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
    // NOTE: fvliid is used below to distinguish between multiple VLAN interfaces
    //   for the same customer in the same peering LAN

    // only define one filter per ASN
use IXP\Models\Aggregators\IrrdbAggregator;
use IXP\Models\Customer;

$asn_filters = [];
?>


########################################################################################
########################################################################################
#
# Route server clients
#
########################################################################################
########################################################################################

<?php foreach( $t->ints as $int ):

        // do not set up a session to ourselves!
        if( $int['autsys'] == $t->router->asn ):
            continue;
        endif;
?>

########################################################################################
########################################################################################
###
### AS<?= $int['autsys'] ?> - <?= $int['cname'] ?> - VLAN Interface #<?= $int['vliid'] ?>

<?php
    if( !in_array( $int['autsys'], $asn_filters ) ):

        $asn_filters[] = $int['autsys'];
?>


filter f_import_as<?= $int['autsys'] . "\n" ?>
{
    prefix set allnet4;
<?php if( $t->router->protocol == 6 ): ?>
    prefix set allnet6;
<?php endif; ?>
    ip set allips;
    int set allas;

<?php
    // IXP Manager UI based filters:
    echo $t->insert( 'api/v4/router/server/bird2/f_ui_import', [ 'int' => $int ] );

    // We allow per customer AS headers here which IXPs can define as skinned files.
    // For example, to solve a Facebook issue, INEX created the following:
    //     resources/skins/inex/api/v4/router/server/bird2/f_import_as32934.foil.php
    echo $t->insertif( 'api/v4/router/server/bird2/f_import_as' . $int['autsys'] );

?>

    # RFC 8326 - facilitate Graceful BGP Session Shutdown
    if (65535, 0) ~ bgp_community then bgp_local_pref = 0;

    # Filter small prefixes
<?php if( $t->router->protocol == 6 ): ?>
    if ( net.type = NET_IP6 && net ~ [ ::/0{<?= config( 'ixp.irrdb.min_v6_subnet_size', 48 ) == 128 ? 128 : config( 'ixp.irrdb.min_v6_subnet_size', 48 ) + 1 ?>,128} ] ) then {
        bgp_large_community.add( IXP_LC_FILTERED_PREFIX_LEN_TOO_LONG );
        reject "[asn=", <?= $int['autsys'] ?>, "] Prefix length too long [", net.len, "] - REJECTING ", net;
    }
<?php endif; ?>
    if ( net.type = NET_IP4 && net ~ [ 0.0.0.0/0{<?= config( 'ixp.irrdb.min_v4_subnet_size', 24 ) == 32 ? 32 : config( 'ixp.irrdb.min_v4_subnet_size', 24 ) + 1 ?>,32} ] ) then {
        bgp_large_community.add( IXP_LC_FILTERED_PREFIX_LEN_TOO_LONG );
        reject "[asn=", <?= $int['autsys'] ?>, "] Prefix length too long [", net.len, "] - REJECTING ", net;
    }

    #########
    # RFC9234
    #########
<?php if( $int['autsys'] == 213973 ): // BCIX OUTREACH  ?>
    if ! defined( bgp_otc ) then {
        bgp_otc = 213973;
    }
<?php else: ?>
    if defined( bgp_otc ) then {
        bgp_large_community.add( IXP_LC_FILTERED_ROUTE_LEAK_DETECTED );
        reject "[asn=", <?= $int['autsys'] ?>, "] Route leak detected RFC9234 [otc=", bgp_otc ,"] - REJECTING ", net;
    }
<?php endif; ?>

    if !(avoid_martians()) then {
        bgp_large_community.add( IXP_LC_FILTERED_BOGON );
        reject "[asn=", <?= $int['autsys'] ?>, "] An IP Bogon was detected - REJECTING ", net;
    }

    # Belt and braces: must have at least one ASN in the path
    if( bgp_path.len < 1 ) then {
        bgp_large_community.add( IXP_LC_FILTERED_AS_PATH_TOO_SHORT );
        reject "[asn=", <?= $int['autsys'] ?>, "] AS path too short [", bgp_path.len ,"] - REJECTING ", net;
    }

    # Peer ASN == route's first ASN?
    if (bgp_path.first != <?= $int['autsys'] ?> ) then {
        bgp_large_community.add( IXP_LC_FILTERED_FIRST_AS_NOT_PEER_AS );
        reject "[asn=", <?= $int['autsys'] ?>, "] First AS not peer AS [", bgp_path.first, "] - REJECTING ", net;
    }

    # set of all IPs this ASN uses to peer with on this VLAN
    allips = [ <?= implode( ', ', $int['allpeeringips'] ) ?> ];

    # Prevent BGP NEXT_HOP Hijacking
    if !( from = bgp_next_hop ) then {

        # need to differentiate between same ASN next hop or actual next hop hijacking
        if( bgp_next_hop ~ allips ) then {
            bgp_large_community.add( IXP_LC_INFO_SAME_AS_NEXT_HOP );
        } else {
            # looks like hijacking (intentional or not)
            bgp_large_community.add( IXP_LC_FILTERED_NEXT_HOP_NOT_PEER_IP );
            ###
            # messages keep spamming the log
            # reject "[asn=", <?= $int['autsys'] ?>, "] Next hop not peer IP [", bgp_next_hop, "] - REJECTING ", net;
            reject;
        }
    }


    # Filter Known Transit Networks
    if filter_has_transit_path() then reject "[asn=", <?= $int['autsys'] ?>, "] transit-free ASN in AS-Path - REJECTING ", net;

    # Filter Known Bogon as in path
    if filter_has_bogon_as_path() then reject "[asn=", <?= $int['autsys'] ?>, "] AS path contains a bogon AS - REJECTING ", net;

    # Belt and braces: no one needs an ASN path with > 64 hops, that's just broken
    if( bgp_path.len > 64 ) then {
        bgp_large_community.add( IXP_LC_FILTERED_AS_PATH_TOO_LONG );
        reject "[asn=", <?= $int['autsys'] ?>, "] AS path too long [", bgp_path.len ,"] - REJECTING ", net;
    }


<?php
    // Only do IRRDB ASN filtering if this is enabled per client:
    $asns = [];
    if( $int['irrdbfilter'] ?? true ):
        $asns = IrrdbAggregator::asnsForRouterConfiguration( $int[ 'cid' ], 4 );
        if( $t->router->protocol == 6 ):
            $asns = array_merge($asns, IrrdbAggregator::asnsForRouterConfiguration( $int[ 'cid' ], 6 ));
        endif;
    $asns = array_unique(array_merge($asns, array($int['autsys'])));
        if( count( $asns ) ):
?>

    allas = [ <?php echo $t->softwrap( $asns, 10, ", ", ",", 14, 7 ); ?>

    ];

<?php   else: ?>

    allas = [ <?= $int['autsys'] ?> ];

<?php   endif; ?>

    # Ensure origin ASN is in the neighbors AS-SET
    if !(bgp_path.last_nonaggregated ~ allas) then {
        bgp_large_community.add( IXP_LC_FILTERED_IRRDB_ORIGIN_AS_FILTERED );
        reject "[asn=", <?= $int['autsys'] ?>, "] Origin AS not in peer AS-SET - REJECTING ", net;
    }

    # Ensure the bgp_path is in the neighbors AS-SET
    bool intermediate_outside_cone = false;
    bool nonaggregated = true;
    for int asn_on_path in bgp_path do {
        if (asn_on_path = bgp_path.last_nonaggregated) then 
            nonaggregated = false;
        if (nonaggregated && !(asn_on_path ~ allas)) then {
            bgp_large_community.add( ( routeserverasn, 1900, asn_on_path ) );
            intermediate_outside_cone = true;
        }
    }
    if (intermediate_outside_cone) then {
            bgp_large_community.add( IXP_LC_FILTERED_INTERMEDIATE_AS_OUTSIDE_CONE );
            reject "[asn=", <?= $int['autsys'] ?>, "] Intermediate AS on path not in peer AS-SET - REJECTING ", net;
    }

<?php endif; ?>

<?php if( $t->router->rpki && config( 'ixp.rpki.rtr1.host' ) ): ?>

    # RPKI check
    if net.type = NET_IP4 then {
        if( roa_check( t_roa4 ) = ROA_INVALID ) then {
            print "Tagging invalid ROA ", net, " for ASN ", bgp_path.last;
            bgp_large_community.add( IXP_LC_FILTERED_RPKI_INVALID );
            reject "[asn=", <?= $int['autsys'] ?>, "] Prefix is RPKI INVALID - REJECTING ", net;
        }

        if( roa_check( t_roa4 ) = ROA_VALID ) then {
            bgp_large_community.add( IXP_LC_INFO_RPKI_VALID );
            accept;
        }
<?php if( $t->router->protocol == 6 ): ?>
    } else {
        if( roa_check( t_roa6 ) = ROA_INVALID ) then {
            print "Tagging invalid ROA ", net, " for ASN ", bgp_path.last;
            bgp_large_community.add( IXP_LC_FILTERED_RPKI_INVALID );
            reject "[asn=", <?= $int['autsys'] ?>, "] Prefix is RPKI INVALID - REJECTING ", net;
        }

        if( roa_check( t_roa6 ) = ROA_VALID ) then {
            bgp_large_community.add( IXP_LC_INFO_RPKI_VALID );
            accept;
        }
<?php endif; ?>
    }

    # RPKI unknown, keep checking and mark as unknown for info
    bgp_large_community.add( IXP_LC_INFO_RPKI_UNKNOWN );

<?php else: ?>

    # Skipping RPKI check -> RPKI not enabled / configured correctly.
    bgp_large_community.add( IXP_LC_INFO_RPKI_NOT_CHECKED );

<?php endif; ?>


<?php
    // Only do IRRDB prefix filtering if this is enabled per client:
    $prefixes = [];
    $afis = [];
    if( $int['irrdbfilter'] ?? true ):

        if( $t->router->protocol == 4 ):
            $afis = [4];
        else:
            $afis = [4, 6];
        endif;
    foreach( $afis as $afi ):
        $prefixes = IrrdbAggregator::prefixesForRouterConfiguration( $int[ 'cid' ], $afi );

            if( count( $prefixes ) ):

?>

    allnet<?= $afi ?> = [ <?php echo $t->softwrap( $int['rsmorespecifics']
        ? $t->bird()->prefixExactToLessSpecific( $prefixes, $afi, config( 'ixp.irrdb.min_v' . $afi . '_subnet_size' ) )
                : $prefixes, 4, ", ", ",", 15, $afi === 6 ? 36 : 26 ); ?>
    ];

    <?php unset( $prefixes ); ?>

    if net.type = NET_IP<?= $afi ?> then {
        if ! (net ~ allnet<?= $afi ?>) then {
            bgp_large_community.add( IXP_LC_FILTERED_IRRDB_PREFIX_FILTERED );
            bgp_large_community.add( <?= $int['rsmorespecifics'] ? 'IXP_LC_INFO_IRRDB_FILTERED_LOOSE' : 'IXP_LC_INFO_IRRDB_FILTERED_STRICT' ?> );
            reject;
        } else {
            bgp_large_community.add( IXP_LC_INFO_IRRDB_VALID );
        }
    }

<?php   else: ?>

    if net.type = NET_IP<?= $afi ?> then {
        # Deny everything because the IRR database returned nothing
        bgp_large_community.add( IXP_LC_FILTERED_IRRDB_PREFIX_FILTERED );
        bgp_large_community.add( IXP_LC_INFO_IRRDB_PREFIX_EMPTY );
        reject "[asn=", <?= $int['autsys'] ?>, "] IRRDB Prefix not found in AS-SET, IRRDB Prefix is empty - REJECTING ", net;
    }

<?php   endif; ?>
<?php endforeach; ?>

<?php else: ?>

    # This ASN was configured not to use IRRDB filtering
    bgp_large_community.add( IXP_LC_INFO_IRRDB_NOT_CHECKED );

<?php endif; ?>

    accept;
}


# The route server export filter exists as the export gateway on the BGP protocol.
#
# Remember that standard IXP community filtering has already happened on the
# master -> bgp protocol pipe.

filter f_export_as<?= $int['autsys'] ?>
{

<?php
    // We allow per customer AS export code here which IXPs can define as skinned files.
    // For example, to solve a Facebook issue, INEX created the following:
    //     resources/skins/api/v4/router/server/bird2/f_export_as32934.foil.php
    echo $t->insertif( 'api/v4/router/server/bird2/f_export_as' . $int['autsys'] );
?>

    if ! (ixp_community_filter(<?= $int['autsys'] ?>) ) then reject;

    if bgp_large_community ~ [( routeserverasn, 1101, * )] then reject;

    #####Graceful BGP Session Shutdown####
    if (65535, 0) ~ bgp_community then {
        bgp_local_pref = 0;
    }

    # we should strip our own communities which we used for the looking glass
    bgp_large_community.delete( [( routeserverasn, *, * )] );
    bgp_community.delete( [( routeserverasn, * ), ( 0, * )] );

    # default position is to accept:
    accept;

}
<?php
    endif; // if( !in_array( $asn_filters[ $int['autsys'] ] ) ):
?>


protocol bgp pb_<?= $int['fvliid'] ?>_as<?= $int['autsys'] ?> from tb_rsclient {
    description "AS<?= $int['autsys'] ?> - <?= $int['cname'] ?>";
    neighbor <?= $int['address'] ?> as <?= $int['autsys'] ?>;
<?php if(
        $t->router->protocol == 4 ||
        ($t->router->protocol == 6 &&
        !in_array('norfc8950', Customer::find($int['cid'])->tags()->pluck('tag')->toArray()))):
        ?>
    ipv4 {
<?php   if( $t->router->protocol == 6 ): ?>
        extended next hop on;
<?php   endif; ?>
        table master4;
        import table on;  # Automatic channel reloads based on RPKI changes
        import limit <?= (Customer::find($int['cid'])->maxprefixes ?? config('ixp.default_maxprefixes.v4')) ?> action restart;
        import filter f_import_as<?= $int['autsys'] ?>;
        export filter f_export_as<?= $int['autsys'] ?>;
        import keep filtered on;
        ###add-path support RFC7911###
        add paths tx;
    };
<?php else: ?>
    # RFC8950 disabled by tag norfc8950 in ixp-manager
<?php endif; ?>
<?php if( $t->router->protocol == 6 ): ?>
    ipv6 {
        table master6;
        import table on;  # Automatic channel reloads based on RPKI changes
        import limit <?= (Customer::find($int['cid'])->maxprefixesv6 ?? config('ixp.default_maxprefixes.v6')) ?> action restart;
        import filter f_import_as<?= $int['autsys'] ?>;
        export filter f_export_as<?= $int['autsys'] ?>;
        import keep filtered on;
        ###add-path support RFC7911###
        add paths tx;
    };
<?php endif; ?>
    passive on;
<?php if( $t->router->rfc1997_passthru ): ?>
    interpret communities off;  # enable rfc1997 well-known community pass through
<?php endif; ?>
<?php if( $int['bgpmd5secret'] && !$t->router->skip_md5 ): ?>
    password "<?= $int['bgpmd5secret'] ?>";
    authentication md5;
<?php endif; ?>
}

<?php endforeach; ?>
