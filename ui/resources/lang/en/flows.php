<?php

return [
    'default_name' => 'New flow',
    'created' => 'Flow created.',
    'updated' => 'Flow updated.',
    'deleted' => 'Flow deleted.',
    'archived' => 'Flow archived.',
    'restored' => 'Flow restored.',
    'run' => [
        'success' => 'Flow started.',
        'error' => 'Failed to start flow.',
        'image_not_found' => 'Docker image ":image" not found. Build it with: docker build -t :image -f flow/Dockerfile .',
    ],
    'stop' => [
        'success' => 'Flow stopped.',
        'error' => 'Failed to stop flow.',
    ],
    'deploy' => [
        'success' => 'Deployment request sent.',
        'error' => 'Failed to deploy flow.',
    ],
    'undeploy' => [
        'success' => 'Production deployment stopped.',
        'error' => 'Failed to stop deployment.',
    ],
    'archive' => [
        'error_active' => 'Cannot archive a flow with active deployments.',
    ],
    'delete' => [
        'error_active' => 'Cannot delete a flow with active deployments.',
        'error_password' => 'Incorrect password.',
    ],
    'storage' => [
        'updated' => 'Storage updated.',
        'error_active' => 'Cannot edit storage while this deployment is running.',
    ],
];
