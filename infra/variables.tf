variable "aws_region" {
  description = "AWS region to deploy into"
  type        = string
  default     = "us-east-2"
}

variable "instance_type" {
  description = "EC2 instance type (needs enough RAM for app + mongo + otel-collector containers)"
  type        = string
  default     = "t3.small"
}

variable "my_ip" {
  description = "Your current public IP, for restricting SSH access. Get it with: curl -s https://checkip.amazonaws.com"
  type        = string
}
