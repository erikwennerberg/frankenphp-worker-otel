terraform {
  required_version = ">= 1.5"
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
    tls = {
      source  = "hashicorp/tls"
      version = "~> 4.0"
    }
    local = {
      source  = "hashicorp/local"
      version = "~> 2.0"
    }
  }
}

provider "aws" {
  region = var.aws_region
}

data "aws_vpc" "default" {
  default = true
}

data "aws_subnets" "default" {
  filter {
    name   = "vpc-id"
    values = [data.aws_vpc.default.id]
  }
}

data "aws_ami" "ubuntu" {
  most_recent = true
  owners      = ["099720109477"] # Canonical

  filter {
    name   = "name"
    values = ["ubuntu/images/hvm-ssd/ubuntu-jammy-22.04-amd64-server-*"]
  }

  filter {
    name   = "virtualization-type"
    values = ["hvm"]
  }
}

resource "tls_private_key" "deploy" {
  algorithm = "ED25519"
}

resource "local_sensitive_file" "private_key" {
  filename        = "${path.module}/deploy_key.pem"
  content         = tls_private_key.deploy.private_key_openssh
  file_permission = "0600"
}

resource "aws_key_pair" "deploy" {
  key_name   = "frankenphp-otel-demo"
  public_key = tls_private_key.deploy.public_key_openssh
}

resource "aws_security_group" "app" {
  name        = "frankenphp-otel-demo"
  description = "FrankenPHP OTEL demo: SSH (restricted) + app port (public, for k6 Cloud + local curl)"
  vpc_id      = data.aws_vpc.default.id

  ingress {
    description = "SSH from operator IP"
    from_port   = 22
    to_port     = 22
    protocol    = "tcp"
    cidr_blocks = ["${var.my_ip}/32"]
  }

  ingress {
    description = "App HTTP, public (k6 Cloud runners don't have fixed source IPs)"
    from_port   = 8080
    to_port     = 8080
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Project = "frankenphp-otel-demo"
  }
}

resource "aws_instance" "app" {
  ami                    = data.aws_ami.ubuntu.id
  instance_type          = var.instance_type
  key_name               = aws_key_pair.deploy.key_name
  subnet_id              = data.aws_subnets.default.ids[0]
  vpc_security_group_ids = [aws_security_group.app.id]

  user_data = <<-EOF
    #!/bin/bash
    set -euxo pipefail
    apt-get update -y
    apt-get install -y ca-certificates curl docker.io docker-compose-v2
    systemctl enable --now docker
    usermod -aG docker ubuntu
  EOF

  tags = {
    Name    = "frankenphp-otel-demo"
    Project = "frankenphp-otel-demo"
  }

  connection {
    type        = "ssh"
    user        = "ubuntu"
    private_key = tls_private_key.deploy.private_key_openssh
    host        = self.public_ip
    timeout     = "5m"
  }

  # Wait for cloud-init (which installs docker) to finish before touching the box.
  provisioner "remote-exec" {
    inline = [
      "cloud-init status --wait",
      "mkdir -p /home/ubuntu/app",
    ]
  }

  provisioner "file" {
    source      = "${path.module}/../app"
    destination = "/home/ubuntu/app/app"
  }

  provisioner "file" {
    source      = "${path.module}/../docker-compose.yml"
    destination = "/home/ubuntu/app/docker-compose.yml"
  }

  provisioner "file" {
    source      = "${path.module}/../otel-collector-config.yaml"
    destination = "/home/ubuntu/app/otel-collector-config.yaml"
  }

  provisioner "file" {
    source      = "${path.module}/../.env"
    destination = "/home/ubuntu/app/.env"
  }

  provisioner "remote-exec" {
    inline = [
      "cd /home/ubuntu/app && sudo docker compose up -d --build",
    ]
  }
}
