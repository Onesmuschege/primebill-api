<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds 40 clients representing a realistic Kenyan ISP subscriber base.
 *
 * Distribution:
 *   - 28 active   (70%) — paying, healthy accounts
 *   -  6 suspended (15%) — overdue or payment issues
 *   -  4 inactive  (10%) — recently churned
 *   -  2 disabled   (5%) — admin-locked
 *
 * Geography: Western Kenya focus (Bungoma, Kakamega, Kisumu, Trans Nzoia)
 * matching a typical small Kenyan ISP deployment corridor.
 *
 * Phone numbers: valid Kenyan format — Safaricom (07xx), Airtel (073x/074x/075x),
 * Telkom (077x). All unique, all 10 digits starting with 0.
 *
 * Safe to re-run: uses updateOrCreate on phone (unique key).
 */
class ClientSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = User::where('email', 'admin@primebill.co.ke')->value('id');

        $clients = $this->clientData();

        foreach ($clients as $client) {
            Client::updateOrCreate(
                ['phone' => $client['phone']],
                array_merge($client, ['created_by' => $adminId])
            );
        }

        $this->command->info('ClientSeeder: ' . count($clients) . ' clients seeded.');
    }

    private function clientData(): array
    {
        return [
            // ── ACTIVE clients ──────────────────────────────────────────────
            [
                'first_name' => 'James',      'last_name' => 'Wanyama',
                'email'      => 'jwanyama@gmail.com',
                'phone'      => '0712345601', 'id_number' => '28456701',
                'county'     => 'Bungoma',    'town'      => 'Bungoma Town',
                'address'    => 'Kanduyi Estate, House 14',
                'gps_lat'    => 0.5635,       'gps_lng'   => 34.5606,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Grace',      'last_name' => 'Nafula',
                'email'      => 'gnafula@yahoo.com',
                'phone'      => '0722345602', 'id_number' => '31245602',
                'county'     => 'Bungoma',    'town'      => 'Webuye',
                'address'    => 'Webuye West, Plot 7',
                'gps_lat'    => 0.6153,       'gps_lng'   => 34.7712,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Peter',      'last_name' => 'Simiyu',
                'email'      => 'psimiyu@outlook.com',
                'phone'      => '0733345603', 'id_number' => '24567803',
                'county'     => 'Bungoma',    'town'      => 'Kimilili',
                'address'    => 'Kimilili Market Road',
                'gps_lat'    => 0.7831,       'gps_lng'   => 34.7196,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Alice',      'last_name' => 'Nekesa',
                'email'      => 'anekesa@gmail.com',
                'phone'      => '0700345604', 'id_number' => '35678904',
                'county'     => 'Bungoma',    'town'      => 'Malakisi',
                'address'    => 'Malakisi Trading Centre',
                'gps_lat'    => 0.9012,       'gps_lng'   => 34.5301,
                'status'     => 'active',
            ],
            [
                'first_name' => 'David',      'last_name' => 'Barasa',
                'email'      => 'dbarasa@gmail.com',
                'phone'      => '0745345605', 'id_number' => '29012305',
                'county'     => 'Bungoma',    'town'      => 'Chwele',
                'address'    => 'Chwele Market, Stall 3B',
                'gps_lat'    => 0.6892,       'gps_lng'   => 34.4418,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Mary',       'last_name' => 'Wekesa',
                'email'      => 'mwekesa@gmail.com',
                'phone'      => '0756345606', 'id_number' => '32123406',
                'county'     => 'Kakamega',   'town'      => 'Kakamega Town',
                'address'    => 'Milimani Estate, Road 4',
                'gps_lat'    => 0.2827,       'gps_lng'   => 34.7519,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Joseph',     'last_name' => 'Khisa',
                'email'      => 'jkhisa@outlook.com',
                'phone'      => '0767345607', 'id_number' => '26789107',
                'county'     => 'Kakamega',   'town'      => 'Mumias',
                'address'    => 'Mumias Sugar Zone, Block C',
                'gps_lat'    => 0.3364,       'gps_lng'   => 34.4886,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Sarah',      'last_name' => 'Achieng',
                'email'      => 'sachieng@gmail.com',
                'phone'      => '0778345608', 'id_number' => '38901208',
                'county'     => 'Kisumu',     'town'      => 'Kisumu City',
                'address'    => 'Milimani, Aga Khan Road',
                'gps_lat'    => -0.1022,      'gps_lng'   => 34.7617,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Michael',    'last_name' => 'Odhiambo',
                'email'      => 'modhiambo@gmail.com',
                'phone'      => '0789345609', 'id_number' => '27234509',
                'county'     => 'Kisumu',     'town'      => 'Kisumu City',
                'address'    => 'Nyamasaria Estate',
                'gps_lat'    => -0.1156,      'gps_lng'   => 34.7834,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Esther',     'last_name' => 'Anyango',
                'email'      => 'eanyango@gmail.com',
                'phone'      => '0710345610', 'id_number' => '34567010',
                'county'     => 'Kisumu',     'town'      => 'Ahero',
                'address'    => 'Ahero Township Road 2',
                'gps_lat'    => -0.1664,      'gps_lng'   => 34.9198,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Samuel',     'last_name' => 'Mutai',
                'email'      => 'smutai@gmail.com',
                'phone'      => '0721345611', 'id_number' => '25890111',
                'county'     => 'Trans Nzoia', 'town'     => 'Kitale',
                'address'    => 'Kitale Stage Area, Plot 22',
                'gps_lat'    => 1.0154,       'gps_lng'   => 35.0062,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Lucy',       'last_name' => 'Chebet',
                'email'      => 'lchebet@gmail.com',
                'phone'      => '0732345612', 'id_number' => '30123412',
                'county'     => 'Trans Nzoia', 'town'     => 'Kitale',
                'address'    => 'Milimani Kitale, House 5A',
                'gps_lat'    => 1.0221,       'gps_lng'   => 35.0145,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Robert',     'last_name' => 'Kiplangat',
                'email'      => 'rkiplangat@gmail.com',
                'phone'      => '0743345613', 'id_number' => '22456813',
                'county'     => 'Trans Nzoia', 'town'     => 'Endebess',
                'address'    => 'Endebess Market Centre',
                'gps_lat'    => 1.1024,       'gps_lng'   => 34.8973,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Faith',      'last_name' => 'Jepkemboi',
                'email'      => 'fjepkemboi@gmail.com',
                'phone'      => '0754345614', 'id_number' => '36789014',
                'county'     => 'Uasin Gishu', 'town'     => 'Eldoret',
                'address'    => 'Huruma Estate, House 8',
                'gps_lat'    => 0.5143,       'gps_lng'   => 35.2698,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Daniel',     'last_name' => 'Rono',
                'email'      => 'drono@gmail.com',
                'phone'      => '0765345615', 'id_number' => '28901415',
                'county'     => 'Uasin Gishu', 'town'     => 'Eldoret',
                'address'    => 'Langas Estate, Plot 11',
                'gps_lat'    => 0.5267,       'gps_lng'   => 35.2891,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Agnes',      'last_name' => 'Cherono',
                'email'      => 'acherono@yahoo.com',
                'phone'      => '0776345616', 'id_number' => '33012516',
                'county'     => 'Bungoma',    'town'      => 'Bungoma Town',
                'address'    => 'Musikoma, Off Bungoma Road',
                'gps_lat'    => 0.5712,       'gps_lng'   => 34.5524,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Patrick',    'last_name' => 'Wafula',
                'email'      => 'pwafula@gmail.com',
                'phone'      => '0787345617', 'id_number' => '27134617',
                'county'     => 'Bungoma',    'town'      => 'Webuye',
                'address'    => 'Webuye Industrial Area',
                'gps_lat'    => 0.6084,       'gps_lng'   => 34.7643,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Caroline',   'last_name' => 'Namukoya',
                'email'      => 'cnamukoya@gmail.com',
                'phone'      => '0711345618', 'id_number' => '39245718',
                'county'     => 'Kakamega',   'town'      => 'Lugari',
                'address'    => 'Lugari Township, Plot 6',
                'gps_lat'    => 0.3512,       'gps_lng'   => 34.9127,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Kevin',      'last_name' => 'Shiundu',
                'email'      => 'kshiundu@gmail.com',
                'phone'      => '0723345619', 'id_number' => '25378919',
                'county'     => 'Kakamega',   'town'      => 'Butere',
                'address'    => 'Butere Market Road',
                'gps_lat'    => 0.2031,       'gps_lng'   => 34.4937,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Beatrice',   'last_name' => 'Atieno',
                'email'      => 'batieno@gmail.com',
                'phone'      => '0734345620', 'id_number' => '31512020',
                'county'     => 'Kisumu',     'town'      => 'Kondele',
                'address'    => 'Kondele Market, Block B',
                'gps_lat'    => -0.0934,      'gps_lng'   => 34.7521,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Thomas',     'last_name' => 'Owino',
                'email'      => 'towino@outlook.com',
                'phone'      => '0746345621', 'id_number' => '23645121',
                'county'     => 'Kisumu',     'town'      => 'Kisumu City',
                'address'    => 'Mamboleo Estate',
                'gps_lat'    => -0.0812,      'gps_lng'   => 34.8012,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Irene',      'last_name' => 'Adhiambo',
                'email'      => 'iadhiambo@gmail.com',
                'phone'      => '0757345622', 'id_number' => '36778922',
                'county'     => 'Siaya',      'town'      => 'Siaya Town',
                'address'    => 'Siaya Township Road',
                'gps_lat'    => 0.0616,       'gps_lng'   => 34.2886,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Geoffrey',   'last_name' => 'Odero',
                'email'      => 'godero@gmail.com',
                'phone'      => '0768345623', 'id_number' => '28912323',
                'county'     => 'Bungoma',    'town'      => 'Tongaren',
                'address'    => 'Tongaren Centre, Plot 3',
                'gps_lat'    => 0.5923,       'gps_lng'   => 34.6234,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Winnie',     'last_name' => 'Mbone',
                'email'      => 'wmbone@gmail.com',
                'phone'      => '0779345624', 'id_number' => '34023424',
                'county'     => 'Bungoma',    'town'      => 'Kimilili',
                'address'    => 'Kimilili Residential Area',
                'gps_lat'    => 0.7912,       'gps_lng'   => 34.7234,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Charles',    'last_name' => 'Masinde',
                'email'      => 'cmasinde@gmail.com',
                'phone'      => '0790345625', 'id_number' => '26156825',
                'county'     => 'Bungoma',    'town'      => 'Chwele',
                'address'    => 'Chwele Township, Block A',
                'gps_lat'    => 0.6834,       'gps_lng'   => 34.4512,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Judith',     'last_name' => 'Kamau',
                'email'      => 'jkamau@gmail.com',
                'phone'      => '0712445626', 'id_number' => '29289726',
                'county'     => 'Kiambu',     'town'      => 'Thika',
                'address'    => 'Thika Landless, Plot 9',
                'gps_lat'    => -1.0332,      'gps_lng'   => 37.0693,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Brian',      'last_name' => 'Mwangi',
                'email'      => 'bmwangi@gmail.com',
                'phone'      => '0723445627', 'id_number' => '32423127',
                'county'     => 'Kiambu',     'town'      => 'Ruiru',
                'address'    => 'Ruiru Town, Bypass Road',
                'gps_lat'    => -1.1456,      'gps_lng'   => 36.9612,
                'status'     => 'active',
            ],
            [
                'first_name' => 'Lydia',      'last_name' => 'Njoroge',
                'email'      => 'lnjoroge@gmail.com',
                'phone'      => '0734445628', 'id_number' => '37556428',
                'county'     => 'Nakuru',     'town'      => 'Nakuru Town',
                'address'    => 'Section 58, House 3',
                'gps_lat'    => -0.3031,      'gps_lng'   => 36.0800,
                'status'     => 'active',
            ],
            // ── SUSPENDED clients ────────────────────────────────────────────
            [
                'first_name' => 'Hassan',     'last_name' => 'Otieno',
                'email'      => 'hotieno@gmail.com',
                'phone'      => '0745445629', 'id_number' => '25689829',
                'county'     => 'Bungoma',    'town'      => 'Bungoma Town',
                'address'    => 'Nzoia Estate, House 2',
                'gps_lat'    => 0.5601,       'gps_lng'   => 34.5598,
                'status'     => 'suspended',
            ],
            [
                'first_name' => 'Miriam',     'last_name' => 'Ouma',
                'email'      => 'mouma@yahoo.com',
                'phone'      => '0756445630', 'id_number' => '31823030',
                'county'     => 'Kakamega',   'town'      => 'Kakamega Town',
                'address'    => 'Amalemba Market Area',
                'gps_lat'    => 0.2912,       'gps_lng'   => 34.7412,
                'status'     => 'suspended',
            ],
            [
                'first_name' => 'Arnold',     'last_name' => 'Juma',
                'email'      => 'ajuma@gmail.com',
                'phone'      => '0767445631', 'id_number' => '23956431',
                'county'     => 'Kisumu',     'town'      => 'Kisumu City',
                'address'    => 'Obunga Estate, Road 3',
                'gps_lat'    => -0.0723,      'gps_lng'   => 34.7334,
                'status'     => 'suspended',
            ],
            [
                'first_name' => 'Naomi',      'last_name' => 'Awuor',
                'email'      => 'nawuor@gmail.com',
                'phone'      => '0778445632', 'id_number' => '36089132',
                'county'     => 'Homa Bay',   'town'      => 'Homa Bay Town',
                'address'    => 'Homa Bay Township',
                'gps_lat'    => -0.5267,      'gps_lng'   => 34.4573,
                'status'     => 'suspended',
            ],
            [
                'first_name' => 'Edwin',      'last_name' => 'Kipkoech',
                'email'      => 'ekipkoech@gmail.com',
                'phone'      => '0789445633', 'id_number' => '27223333',
                'county'     => 'Uasin Gishu', 'town'     => 'Eldoret',
                'address'    => 'Pioneer Estate, House 14',
                'gps_lat'    => 0.5089,       'gps_lng'   => 35.2612,
                'status'     => 'suspended',
            ],
            [
                'first_name' => 'Sharon',     'last_name' => 'Koech',
                'email'      => 'skoech@outlook.com',
                'phone'      => '0712545634', 'id_number' => '33356834',
                'county'     => 'Nandi',      'town'      => 'Kapsabet',
                'address'    => 'Kapsabet Town, Plot 5',
                'gps_lat'    => 0.2012,       'gps_lng'   => 35.0991,
                'status'     => 'suspended',
            ],
            // ── INACTIVE clients ─────────────────────────────────────────────
            [
                'first_name' => 'Victor',     'last_name' => 'Luyali',
                'email'      => 'vluyali@gmail.com',
                'phone'      => '0723545635', 'id_number' => '24490135',
                'county'     => 'Bungoma',    'town'      => 'Malakisi',
                'address'    => 'Malakisi Centre',
                'gps_lat'    => 0.8934,       'gps_lng'   => 34.5198,
                'status'     => 'inactive',
            ],
            [
                'first_name' => 'Consolata',  'last_name' => 'Nyongesa',
                'email'      => 'cnyongesa@gmail.com',
                'phone'      => '0734545636', 'id_number' => '37623736',
                'county'     => 'Kakamega',   'town'      => 'Khayega',
                'address'    => 'Khayega Market',
                'gps_lat'    => 0.3145,       'gps_lng'   => 34.6712,
                'status'     => 'inactive',
            ],
            [
                'first_name' => 'Emmanuel',   'last_name' => 'Oloo',
                'email'      => 'eoloo@gmail.com',
                'phone'      => '0745545637', 'id_number' => '26757037',
                'county'     => 'Kisumu',     'town'      => 'Muhoroni',
                'address'    => 'Muhoroni Sugar Belt',
                'gps_lat'    => -0.1523,      'gps_lng'   => 35.1912,
                'status'     => 'inactive',
            ],
            [
                'first_name' => 'Dorcas',     'last_name' => 'Sang',
                'email'      => 'dsang@gmail.com',
                'phone'      => '0756545638', 'id_number' => '30890438',
                'county'     => 'Kericho',    'town'      => 'Kericho Town',
                'address'    => 'Kericho Green Zone',
                'gps_lat'    => -0.3667,      'gps_lng'   => 35.2834,
                'status'     => 'inactive',
            ],
            // ── DISABLED clients ─────────────────────────────────────────────
            [
                'first_name' => 'Francis',    'last_name' => 'Mwenda',
                'email'      => 'fmwenda@gmail.com',
                'phone'      => '0767545639', 'id_number' => '22024239',
                'county'     => 'Bungoma',    'town'      => 'Bungoma Town',
                'address'    => 'Bungoma CBD, Floor 2',
                'gps_lat'    => 0.5645,       'gps_lng'   => 34.5589,
                'status'     => 'disabled',
            ],
            [
                'first_name' => 'Purity',     'last_name' => 'Auma',
                'email'      => 'pauma@gmail.com',
                'phone'      => '0778545640', 'id_number' => '35157740',
                'county'     => 'Kisumu',     'town'      => 'Kisumu City',
                'address'    => 'Kaloleni Estate',
                'gps_lat'    => -0.0978,      'gps_lng'   => 34.7689,
                'status'     => 'disabled',
            ],
        ];
    }
}