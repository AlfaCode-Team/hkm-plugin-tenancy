<?php

declare(strict_types=1);

/**
 * French copy for the Tenancy plugin's admin screens.
 *
 * "Tenant" is kept as « locataire », the term used consistently across this
 * platform's French copy. It is a deliberate choice over the untranslated
 * English word: these screens are operated by administrators who may not be
 * developers, and a half-English interface reads as unfinished.
 *
 * Technical identifiers stay in English where they are literally the name of a
 * thing the operator will type or look up — "slug", "driver" — because
 * translating them would break the correspondence with the config keys and CLI
 * flags they map to.
 */
return [
    'common' => [
        'loading'     => 'Chargement…',
        'reload'      => 'Actualiser',
        'name'        => 'Nom',
        'slug'        => 'Slug',
        'status'      => 'Statut',
        'database'    => 'Base de données',
        'cancel'      => 'Annuler',
        'delete'      => 'Supprimer',
        'edit'        => 'Modifier',
        'back'        => 'Retour',
        'loaded_from' => 'Chargé depuis',
    ],

    'nav' => [
        'brand'   => 'Locataires',
        'tenants' => 'Vos locataires',
        'manage'  => 'Gérer',
        'hosts'   => 'Domaines',
    ],

    'index' => [
        'title'  => 'Vos locataires',
        'role'   => 'Rôle',
        'scoped' => 'Vous travaillez maintenant sur :name en tant que :role.',
        'select' => 'Sélectionner',
        'empty'  => 'Vous n\'êtes membre d\'aucun locataire pour le moment.',
    ],

    'manage' => [
        'title' => 'Locataires',
        'empty' => 'Aucun locataire. Créez le premier.',
    ],

    'create' => [
        'title'        => 'Provisionner un nouveau locataire',
        'display_name' => 'Nom affiché',
        'physical_db'  => 'Nom physique de la base de données',
        'db_driver'    => 'Driver de base de données',
        'db_host'      => 'Hôte de la base de données',
        'db_port'      => 'Port de la base de données',
        'db_user'      => 'Utilisateur de la base de données',
        'db_password'  => 'Mot de passe de la base de données',
        'provisioned'  => 'Locataire « :name » provisionné.',
        'submit'       => 'Provisionner le locataire',
    ],

    'edit' => [
        'title'  => 'Modifier le locataire',
        'submit' => 'Enregistrer les modifications',
    ],

    'hosts' => [
        'title'        => 'Domaines',
        'add_title'    => 'Ajouter un domaine personnalisé',
        'hostname'     => 'Nom d\'hôte',
        'expected_a'   => 'Enregistrement A attendu (facultatif)',
        'register'     => 'Enregistrer le domaine',
        'empty'        => 'Aucun domaine enregistré pour le moment.',
        'primary'      => 'Principal',
        'make_primary' => 'Définir comme principal',
        'verified'     => 'Vérifié',
        'verify'       => 'Vérifier',
        'publish_dns'  => 'Publiez cet enregistrement DNS',
    ],
];
