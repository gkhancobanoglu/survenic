pipeline {
  agent any

  environment {
    REGISTRY    = "docker.io"
    IMAGE_NAME  = "gkhancobanoglu/survenic"
    REG_CRED_ID = "dockerhub-creds"
  }

  options { timestamps() }

  stages {
    stage('Checkout') {
      steps { checkout scm }
    }

    stage('Compute Version') {
      steps {
        script {
          def branch = env.BRANCH_NAME ?: sh(script: "git rev-parse --abbrev-ref HEAD", returnStdout: true).trim()
          env.BRANCH_NAME = branch
          env.GIT_SHA     = sh(script: 'git rev-parse --short HEAD', returnStdout: true).trim()
          env.VERSION     = "${branch}-${env.BUILD_NUMBER}-${env.GIT_SHA}"
          env.IMAGE_TAG   = env.VERSION
        }
        echo "Version: ${env.VERSION}"
      }
    }

    stage('Smoke: Docker CLI') {
      steps {
        sh '''
          set -e
          docker version
          docker info | head -n 20
        '''
      }
    }

    stage('Build Image') {
      steps {
        ansiColor('xterm') {
          sh """
            docker build \
              --label org.opencontainers.image.revision=${GIT_SHA} \
              --label org.opencontainers.image.version=${IMAGE_TAG} \
              -t ${REGISTRY}/${IMAGE_NAME}:${IMAGE_TAG} .
            docker tag ${REGISTRY}/${IMAGE_NAME}:${IMAGE_TAG} ${REGISTRY}/${IMAGE_NAME}:${GIT_SHA}
            ${env.BRANCH_NAME == 'main' ? "docker tag ${REGISTRY}/${IMAGE_NAME}:${IMAGE_TAG} ${REGISTRY}/${IMAGE_NAME}:latest" : "true"}
          """
        }
      }
    }

    stage('Push Image') {
      steps {
        ansiColor('xterm') {
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

  post {
    success { echo "✅ Pushed: ${REGISTRY}/${IMAGE_NAME}:${IMAGE_TAG} ${env.BRANCH_NAME == 'main' ? '(+ latest)' : ''}" }
    failure { echo "❌ Pipeline failed" }
  }
}
