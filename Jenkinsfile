node {
  wrap([$class: 'AnsiColorBuildWrapper', cxolorMapName: 'xterm']) {
    properties([
        parameters([
            choice(
                choices: "2.3.48\n2.2.175\n2.1.84",
                description: 'Select a platform package reference.',
                name: 'platformPackageReference')
            ]),
        pipelineTriggers([])
    ])

    checkout scm
    sh "composer install --no-interaction --no-suggest"
    def inventory = readYaml file: "/var/jenkins_home/workspace/drone-downstream/default/config/inventory.yml"
    println inventory
  }
}


