pipeline {
  agent any

  environment {
    REGISTRY    = "docker.io"
    IMAGE_NAME  = "gkhancobanoglu/survenic"
    REG_CRED_ID = "dockerhub-creds"
  }

  options { timestamps(); ansiColor('xterm') }

  stages {
    stage('Checkout') {
      steps { checkout scm }
    }

    stage('Compute Version') {
      steps {
        script {
          env.GIT_SHA   = sh(script: 'git rev-parse --short HEAD', returnStdout: true).trim()
          env.VERSION   = "${env.BRANCH_NAME}-${env.BUILD_NUMBER}-${env.GIT_SHA}"
          env.IMAGE_TAG = env.VERSION
        }
        echo "Version: ${env.VERSION}"
      }
    }

    stage('Build Image') {
      steps {
        sh """
          docker build -t ${REGISTRY}/${IMAGE_NAME}:${IMAGE_TAG} .
          docker tag ${REGISTRY}/${IMAGE_NAME}:${IMAGE_TAG} ${REGISTRY}/${IMAGE_NAME}:${GIT_SHA}
          ${env.BRANCH_NAME == 'main' ? "docker tag ${REGISTRY}/${IMAGE_NAME}:${IMAGE_TAG} ${REGISTRY}/${IMAGE_NAME}:latest" : "true"}
        """
      }
    }

    stage('Push Image') {
      steps {
        withCredentials([usernamePassword(credentialsId: env.REG_CRED_ID, usernameVariable: 'U', passwordVariable: 'P')]) {
          sh """
            echo "$P" | docker login ${REGISTRY} -u "$U" --password-stdin
            docker push ${REGISTRY}/${IMAGE_NAME}:${IMAGE_TAG}
            docker push ${REGISTRY}/${IMAGE_NAME}:${GIT_SHA}
            ${env.BRANCH_NAME == 'main' ? "docker push ${REGISTRY}/${IMAGE_NAME}:latest" : "true"}
            docker logout ${REGISTRY}
          """
        }
      }
    }
  }
}
